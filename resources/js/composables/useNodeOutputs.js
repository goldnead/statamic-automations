/**
 * The canvas half of the node-output contract.
 *
 * Which handles a node has is declared once, by the node, in PHP
 * (`outputSpec()` — see `src/Support/NodeOutputs.php` for the grammar and the
 * reasoning). The registry ships that declaration to the browser inside the
 * node-library payload; this file resolves it against a node's live config.
 *
 * Before 1.7.0 the canvas held its own copy of the rule instead: a chain of
 * `if (type === 'switch')` / `'loop'` / `'parallel'` in `useAutoLayout.js`,
 * including how each of them reads `config.cases` and `config.branches`. The
 * mirror was accurate for the built-ins and empty for everybody else — a
 * third-party node got one `default` handle whatever it declared in PHP,
 * because `NodeRegistry::describe()` did not expose outputs at all.
 *
 * The outputs have to be resolvable synchronously: they are read during
 * layout, during render, and again while the user is typing into a switch's
 * cases. So the payload is a spec the frontend evaluates, not a resolved list
 * fetched per keystroke.
 *
 * ## Where the specs come from
 *
 * `setNodeOutputSpecs(library)` is called once by the page that owns the
 * canvas (`pages/Automations/Edit.vue`) with the `library` prop the server
 * rendered. The store is module-level rather than provided/injected because
 * `outputsFor()` is called from pure helpers (`useAutoLayout`,
 * `useGraphMutations`) that take plain data and no component context.
 *
 * A node type with no spec — an unknown type, a node from an addon that has
 * since been removed, or any node at all before the library has been set —
 * gets the same single `default` continuation the canvas gave every custom
 * node before this release. It is the one assumption that cannot make a
 * stored graph unreadable: an edge on a handle the node does not offer still
 * lays out (`computeLayout` trails unexpected outputs after the declared
 * ones) and is still saved untouched.
 */

/**
 * Version of the spec grammar this canvas understands.
 *
 * Kept in step with `NodeOutputs::VERSION` in PHP. The two travel together in
 * one package, but the built assets are published into the host's
 * `public/vendor/`, so a stale copy meeting a newer server is a real shape.
 * A spec numbered higher than this is therefore not guessed at: it resolves
 * to the single `default` output, which is what a canvas that had never heard
 * of output specs did with the same node. Lower numbers stay readable — every
 * field this version understands is one an older payload simply does not use.
 */
export const OUTPUT_SPEC_VERSION = 1;

/** What a node has when nothing says otherwise. */
const DEFAULT_OUTPUTS = [{ handle: 'default', label: '' }];

/** type → spec, filled by setNodeOutputSpecs(). */
const specs = new Map();

/** Types already warned about, so a stale payload logs once, not per render. */
const warned = new Set();

/**
 * Register the output specs carried by a node-library payload.
 *
 * Accepts the `{ triggers, logic, actions }` shape the CP pages are handed,
 * or a flat array of node descriptions.
 */
export function setNodeOutputSpecs(library) {
    specs.clear();
    warned.clear();

    const entries = Array.isArray(library)
        ? library
        : [
            ...(library?.triggers ?? []),
            ...(library?.logic ?? []),
            ...(library?.actions ?? []),
        ];

    for (const entry of entries) {
        if (entry?.handle && entry.outputs) specs.set(entry.handle, entry.outputs);
    }

    return specs.size;
}

/** Drop every registered spec — for tests, and for tearing a page down. */
export function clearNodeOutputSpecs() {
    specs.clear();
    warned.clear();
}

/** The registered spec for a node type, or null. */
export function outputSpecFor(type) {
    return specs.get(type) ?? null;
}

/**
 * Normalize a `key_value` config field (as produced by the backend's
 * NormalizesKeyValue trait) into an ordered array of [key, value] pairs.
 * Accepts the shapes that field can realistically arrive in on the frontend:
 * a plain object map (the normal case — Laravel serializes an associative
 * array to a JSON object), a list of {key,value}/{handle,label} pairs, a
 * list of [key, value] tuples, or a raw JSON string (e.g. mid-edit in the
 * key_value Textarea before it round-trips through a save). Anything else
 * (missing, malformed) degrades to an empty list — never throws.
 */
export function keyValueEntries(raw) {
    if (raw == null) return [];
    if (typeof raw === 'string') {
        try {
            return keyValueEntries(JSON.parse(raw));
        } catch {
            return [];
        }
    }
    if (Array.isArray(raw)) {
        return raw
            .map((item) => {
                if (Array.isArray(item)) return [item[0], item[1]];
                if (item && typeof item === 'object') {
                    const key = item.key ?? item.handle;
                    const value = item.value ?? item.label;
                    return key != null ? [key, value] : null;
                }
                return null;
            })
            .filter((pair) => pair != null && pair[0] != null);
    }
    if (typeof raw === 'object') return Object.entries(raw);
    return [];
}

function asString(value) {
    if (value == null) return '';
    if (typeof value === 'object') return '';
    return String(value);
}

/** [{handle,label}] from the loose shapes a spec may write. */
function normalizeList(outputs) {
    if (!Array.isArray(outputs)) return [];

    return outputs.map((out) =>
        out && typeof out === 'object'
            ? { handle: asString(out.handle), label: asString(out.label) }
            : { handle: asString(out), label: '' },
    );
}

function clauseApplies(clause, config) {
    const when = clause?.when;
    if (!when || !when.field) return true;

    let value = asString(config?.[when.field]);
    if (value === '') value = asString(when.default);

    if (Array.isArray(when.is)) return when.is.map(asString).includes(value);
    if (Array.isArray(when.not)) return !when.not.map(asString).includes(value);

    return true;
}

function fromKeyValue(from, config) {
    const handleSide = from.handle ?? 'key';
    const labelSide = from.label ?? 'value';
    const handleFallback = asString(from.handle_fallback);
    const labelFallback = asString(from.label_fallback);

    return keyValueEntries(config?.[from.field]).map(([key, value]) => {
        const side = (which) => (which === 'key' ? asString(key) : asString(value));

        let handle = side(handleSide);
        if (handle === '') handle = handleFallback;

        let label = side(labelSide);
        if (label === '' && labelFallback === 'handle') label = handle;

        return { handle, label };
    });
}

/**
 * Resolve a spec against a config — the mirror of `NodeOutputs::resolve()`.
 * Same clauses, same order, same dedupe, same `primary` marking.
 *
 * @returns {Array<{handle: string, label: string, primary?: boolean}>}
 */
export function resolveOutputSpec(spec, config = {}) {
    if (!spec || !Array.isArray(spec.clauses) || spec.clauses.length === 0) return [];

    if (Number(spec.version ?? OUTPUT_SPEC_VERSION) > OUTPUT_SPEC_VERSION) {
        return DEFAULT_OUTPUTS.map((out) => ({ ...out }));
    }

    const clause = spec.clauses.find((candidate) => clauseApplies(candidate, config));
    if (!clause) return [];

    const rows = [
        ...normalizeList(clause.outputs ?? []),
        ...(clause.from ? fromKeyValue(clause.from, config) : []),
        ...normalizeList(clause.append ?? []),
    ];

    const seen = new Set();
    const outputs = [];
    for (const row of rows) {
        if (row.handle === '' || seen.has(row.handle)) continue;
        seen.add(row.handle);
        outputs.push(row);
    }

    const primary = spec.primary == null ? null : asString(spec.primary);
    if (primary !== null) {
        for (const out of outputs) if (out.handle === primary) out.primary = true;
    }

    return outputs;
}

/**
 * Ordered outputs a node exposes, left→right, as `{ handle, label }` pairs
 * (the continuation additionally carrying `primary: true`). Every consumer —
 * the layout's column order and open-output detection, NodeCard's handle
 * dots, Canvas's "+" adders, the graph mutations' edge wiring — goes through
 * this one function, so a node's handles cannot mean one thing in one place
 * and something else in another.
 */
export function outputsFor(node) {
    const spec = outputSpecFor(node?.type);

    if (!spec) return DEFAULT_OUTPUTS.map((out) => ({ ...out }));

    if (Number(spec.version ?? OUTPUT_SPEC_VERSION) > OUTPUT_SPEC_VERSION && !warned.has(node.type)) {
        warned.add(node.type);
        // eslint-disable-next-line no-console
        console.warn(
            `[statamic-automations] Node '${node.type}' declares output spec version ${spec.version}, ` +
            `this canvas understands ${OUTPUT_SPEC_VERSION}. Falling back to a single "default" output — ` +
            'the published assets are older than the addon. Re-publish them (php artisan statamic:addons:publish).',
        );
    }

    return resolveOutputSpec(spec, node?.config ?? {});
}

/**
 * The handle a node continues on — the output it declares as `primary`,
 * else its first, else null when it declares none (a `stop`, or a `parallel`
 * whose branches are not configured yet).
 *
 * `primary` is why this is not simply "the first one". A loop's outputs are
 * `loop` then `done`; the copy Duplicate makes belongs after the loop, not
 * inside its body, and until the node could say which output means "and then"
 * there was no way for the canvas to know that.
 */
export function continuationOutput(node) {
    const outputs = outputsFor(node);

    return outputs.find((out) => out.primary)?.handle ?? outputs[0]?.handle ?? null;
}
