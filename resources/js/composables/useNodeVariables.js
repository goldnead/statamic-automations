import { computed, unref } from 'vue';

/**
 * Upstream token variables available to a given node — powers the
 * `{{ }}` token-insertion UI (`TokenInserter.vue`) on `tokenable` config
 * fields in `ConfigPanel.vue`.
 *
 * Walks `automation.edges` BACKWARD, transitively, from `nodeId` — level by
 * level (direct parents first, then grandparents, …) — collecting every
 * ancestor node's `output_schema` (as attached to each entry in the node
 * library's describe payload by `NodeRegistry::describe()`, Task 2.4) and
 * flattening it into dot-path tokens. Cycles are guarded against with a
 * visited-set (the graph is normally acyclic per `FlowValidator`, but this
 * must never hang or crash even on malformed/legacy data).
 *
 * Token syntax mirrors the backend engine exactly:
 *  - A TRIGGER's `outputSchema()` seeds the run context at its ROOT (see
 *    `AutomationTrigger::buildContext()` → `AutomationContext::make($data)`),
 *    so trigger variables are UNPREFIXED: `{{ entry.id }}`.
 *  - Every other executed node (action/logic) has its result recorded under
 *    `nodes.<node_key>.*` (`AutomationContext::recordNodeOutput()`,
 *    `WorkflowRunner::runFrom()`), so their variables are prefixed:
 *    `{{ nodes.<node_key>.entry.id }}`.
 * See `src/Engine/TokenResolver.php` for the resolver these strings feed.
 *
 * @param {string|null|import('vue').Ref<string|null>|(() => string|null)} nodeId
 *   The currently-selected node's `node_key`.
 * @param {Object|import('vue').Ref<Object>|(() => Object)} automation
 *   The automation graph, `{ nodes: [...], edges: [...] }` — `nodes` carry
 *   `node_key`/`type`; `edges` carry `from_node_key`/`from_output`/`to_node_key`
 *   (see `resources/js/pages/Automations/Edit.vue`).
 * @param {Object|import('vue').Ref<Object>|(() => Object)} library
 *   The node library, `{ triggers: [...], logic: [...], actions: [...] }`;
 *   each entry carries `handle`, `label`, and — when the node class defines
 *   it — `output_schema` (see `NodesController::index()`).
 * @param {Object|null|import('vue').Ref<Object|null>|(() => Object|null)} [run]
 *   Optional last/test run (`{ node_runs: [...] }` from `AutomationsController::test`).
 *   When present, each variable gets a `sample` string resolved from the
 *   matching node's recorded `output` (see `buildSampleIndex`), so the picker
 *   can show `entry.title → "My Post"`. Absent/blank ⇒ `sample: null` — this
 *   is purely additive and never gates whether a token is offered.
 * @returns {import('vue').ComputedRef<Array<{token: string, label: string, source: string, sample: string|null}>>}
 *   Nearest-upstream-first, deduped by token. Empty array (never throws)
 *   when the node has no reachable ancestors or none expose variables.
 */
export function useNodeVariables(nodeId, automation, library, run = null) {
    return computed(() => {
        const currentKey = resolve(nodeId);
        const graph = resolve(automation) ?? {};
        const lib = resolve(library) ?? {};
        const nodes = Array.isArray(graph.nodes) ? graph.nodes : [];
        const edges = Array.isArray(graph.edges) ? graph.edges : [];

        if (!currentKey || !nodes.length) return [];

        // Resolved sample values from the most recent (test) run, keyed by the
        // exact same token strings we build below. Empty object when no run.
        const sampleIndex = buildSampleIndex(resolve(run), lib);

        const nodesByKey = new Map(nodes.map((n) => [n.node_key, n]));

        // Backward BFS: frontier 1 = direct parents of currentKey, frontier 2
        // = their parents, etc. — a naturally nearest-upstream-first order.
        const ancestorOrder = [];
        const visited = new Set([currentKey]);
        let frontier = [currentKey];
        while (frontier.length) {
            const next = [];
            for (const key of frontier) {
                for (const edge of edges) {
                    if (edge?.to_node_key !== key) continue;
                    const parentKey = edge.from_node_key;
                    if (!parentKey || visited.has(parentKey)) continue;
                    visited.add(parentKey);
                    ancestorOrder.push(parentKey);
                    next.push(parentKey);
                }
            }
            frontier = next;
        }

        const variables = [];
        const seenTokens = new Set();

        for (const nodeKey of ancestorOrder) {
            const node = nodesByKey.get(nodeKey);
            if (!node) continue;

            const meta = findNodeMeta(node.type, lib);
            const outputSchema = meta?.output_schema;
            if (!outputSchema || typeof outputSchema !== 'object') continue;

            const isTrigger = (lib.triggers ?? []).some((t) => t.handle === node.type);
            const sourceLabel = node.label || meta?.label || node.type;

            for (const path of flattenSchema(outputSchema)) {
                const token = isTrigger ? `{{ ${path} }}` : `{{ nodes.${nodeKey}.${path} }}`;

                if (seenTokens.has(token)) continue;
                seenTokens.add(token);

                variables.push({
                    token,
                    label: humanizePath(path),
                    source: sourceLabel,
                    sample: sampleIndex[token] ?? null,
                });
            }
        }

        return variables;
    });
}

/**
 * Builds a `{ token: sampleString }` lookup from a run's `node_runs`, using
 * the SAME token-key convention as `useNodeVariables` (trigger outputs at the
 * unprefixed root, every other node under `nodes.<node_key>.*`). Values are
 * formatted for display by `formatSampleValue`; anything that formats to
 * `null` (blank/empty) is skipped so the picker never shows `→ ""`.
 *
 * Tolerant by design — a missing run, a run without `node_runs`, or a node
 * run with a non-object `output` all yield `{}` rather than throwing. This is
 * an optional enrichment layer; the picker works fine without it.
 *
 * @param {Object|null} run   `{ node_runs: [{ node_key, node_type, output }] }`.
 * @param {Object} library    The node library (to tell triggers from nodes).
 * @returns {Object<string, string>}
 */
export function buildSampleIndex(run, library) {
    const index = {};
    const runs = run && Array.isArray(run.node_runs) ? run.node_runs : [];
    if (!runs.length) return index;

    const lib = library ?? {};
    const triggers = lib.triggers ?? [];

    for (const nodeRun of runs) {
        const output = nodeRun?.output;
        if (!output || typeof output !== 'object' || Array.isArray(output)) continue;

        const isTrigger = triggers.some((t) => t.handle === nodeRun.node_type);

        for (const [path, value] of flattenValues(output)) {
            const formatted = formatSampleValue(value);
            if (formatted === null) continue;
            const token = isTrigger ? `{{ ${path} }}` : `{{ nodes.${nodeRun.node_key}.${path} }}`;
            index[token] = formatted;
        }
    }

    return index;
}

/**
 * Formats a single resolved value for compact display next to a token.
 * Returns `null` for values not worth showing (null/undefined/blank string),
 * so callers can skip them. Long strings and serialised arrays/objects are
 * truncated so the dropdown stays readable.
 *
 * @param {*} value
 * @returns {string|null}
 */
export function formatSampleValue(value) {
    if (value === null || value === undefined) return null;
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed === '' ? null : truncate(trimmed);
    }
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);
    try {
        return truncate(JSON.stringify(value));
    } catch {
        return null;
    }
}

function truncate(str, max = 40) {
    return str.length > max ? `${str.slice(0, max - 1)}…` : str;
}

/**
 * Like `flattenSchema`, but keeps the actual values — flattens a nested value
 * object into `[dotPath, value]` leaf pairs. Arrays are treated as leaves
 * (shown as a compact JSON summary) rather than indexed element-by-element.
 *
 * @param {Object} obj
 * @param {string} prefix
 * @returns {Array<[string, *]>}
 */
function flattenValues(obj, prefix = '') {
    const out = [];
    for (const [key, value] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
            out.push(...flattenValues(value, path));
        } else {
            out.push([path, value]);
        }
    }
    return out;
}

function resolve(value) {
    if (typeof value === 'function') return value();
    return unref(value);
}

function findNodeMeta(type, library) {
    if (!type || !library) return null;
    return (
        (library.triggers ?? []).find((m) => m.handle === type) ??
        (library.logic ?? []).find((m) => m.handle === type) ??
        (library.actions ?? []).find((m) => m.handle === type) ??
        null
    );
}

/**
 * Flattens a nested `output_schema` (as returned by PHP node classes'
 * `outputSchema()`, e.g. `{ entry: { id: 'string', slug: 'string' } }`)
 * into dot-path leaves, e.g. `['entry.id', 'entry.slug']`. A leaf is any
 * non-plain-object value — schemas describe leaves as type-name strings
 * ('string'/'array'/'integer'/'datetime'), but flattening doesn't depend on
 * that; anything non-object just terminates the path.
 *
 * @param {Object} schema
 * @param {string} prefix
 * @returns {Array<string>}
 */
function flattenSchema(schema, prefix = '') {
    const out = [];
    for (const [key, value] of Object.entries(schema)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
            out.push(...flattenSchema(value, path));
        } else {
            out.push(path);
        }
    }
    return out;
}

function humanizeSegment(segment) {
    return segment.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function humanizePath(path) {
    return path.split('.').map(humanizeSegment).join(' › ');
}
