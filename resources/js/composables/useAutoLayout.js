/**
 * Auto-layout for the automation graph (Zapier-style fixed vertical flow).
 *
 * The builder no longer stores hand-placed coordinates. Node positions are
 * DERIVED from the graph structure (trigger → steps → branches) on every
 * render, so the canvas is always a clean, readable top→bottom flow and the
 * "+" insert model is unambiguous: there is exactly one correct slot for each
 * node.
 *
 * Shape assumption: the append/insert UX only ever creates edges where each
 * node has a single incoming edge, so the live graph is a tree (or a small
 * forest of disconnected roots). The algorithm is a classic tidy-tree pass:
 * children are packed left→right at a fixed column span and each parent is
 * centred over its children. Cycles / re-convergence — only reachable through
 * imported or legacy hand-wired data — are guarded against and degrade
 * gracefully (first placement wins).
 *
 * Pure and framework-free so it is unit-testable in isolation.
 */

export const LAYOUT = {
    NODE_WIDTH: 240,
    COLUMN_SPAN: 320, // horizontal distance between sibling branch columns
    ROW_HEIGHT: 200, // vertical distance between depth levels
    ORIGIN_X: 0,
    ORIGIN_Y: 0,
};

const DEFAULT_OPTS = {
    branchTypes: ['branch'],
    terminalTypes: ['stop'],
};

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
function keyValueEntries(raw) {
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

/**
 * Ordered outputs a node exposes, left→right, as `{ handle, label }` pairs.
 * Mirrors the backend's per-node `outputs()` declarations exactly (see
 * src/Nodes/Logic/{SwitchNode,ParallelNode,LoopNode}.php) so the canvas only
 * ever renders handles the engine actually knows how to route:
 *
 * - branch   → fixed true/false.
 * - switch   → `config.cases` is a `{ matchValue: outputHandle }` map; one
 *              output per DISTINCT handle (case value becomes the label),
 *              plus a trailing "default" (deduped if a case already targets
 *              it). Empty/missing cases → just "default".
 * - loop     → fixed handles `loop`/`done`, labelled "For each item"/"After
 *              loop" — the body wired to `loop` runs once per item and then
 *              continues on its own; no loop-back edge is needed.
 * - parallel → only in `inline` mode (the default): `config.branches` is a
 *              `{ outputHandle: label }` map, one output per key. In legacy
 *              `automation` mode the branches are sub-automation runs, not
 *              graph edges, so it exposes a single "default" continuation.
 *              No branches configured → no outputs (never invented).
 * - everything else → a single unlabeled "default" continuation.
 */
export function outputsFor(node, opts = DEFAULT_OPTS) {
    const type = node?.type;
    const config = node?.config ?? {};

    if (opts.terminalTypes.includes(type)) return [];

    if (opts.branchTypes.includes(type)) {
        return [
            { handle: 'true', label: 'True' },
            { handle: 'false', label: 'False' },
        ];
    }

    if (type === 'switch') {
        const seen = new Set();
        const outputs = [];
        for (const [matchValue, output] of keyValueEntries(config.cases)) {
            const handle = String(output || 'default');
            if (seen.has(handle)) continue;
            seen.add(handle);
            outputs.push({ handle, label: String(matchValue) });
        }
        if (!seen.has('default')) outputs.push({ handle: 'default', label: 'Default' });
        return outputs;
    }

    if (type === 'loop') {
        // "loop" starts the per-item body — it runs once for every item and
        // then continues automatically; "done" fires once after every item
        // has run. Neither needs a loop-back edge, hence the plainer labels
        // ("For each item" / "After loop") over the more mechanical
        // "Loop"/"Done", which read as if the body had to close a loop.
        return [
            { handle: 'loop', label: 'For each item' },
            { handle: 'done', label: 'After loop' },
        ];
    }

    if (type === 'parallel') {
        const mode = config.mode || 'inline';
        if (mode !== 'inline') return [{ handle: 'default', label: '' }];
        return keyValueEntries(config.branches).map(([handle, label]) => ({
            handle: String(handle),
            label: label ? String(label) : String(handle),
        }));
    }

    return [{ handle: 'default', label: '' }];
}

/**
 * Evenly distribute `total` handles across a 0..1 axis, e.g. for a branch
 * node handleY(0,2) ≈ 0.33 / handleY(1,2) ≈ 0.67 — matching the previous
 * fixed 32%/68% split. Used as the horizontal fraction of the node's bottom
 * edge (the flow is vertical, so a node's outputs fan out left→right).
 */
export function handleY(index, total) {
    if (!total) return 0.5;
    return (index + 1) / (total + 1);
}

/**
 * Horizontal fraction (0..1) for a given output's handle on a node — the
 * SAME math NodeCard uses to position the rendered Handle dot itself
 * (`handleY(index, outputsFor(node).length)`). Canvas.vue positions the "+"
 * adder and the dashed open-output stub from this single shared function so
 * they can never drift from the actual dot, however many outputs a node has
 * (switch cases, parallel branches, loop/done, …). `output` not found (or no
 * outputs at all) falls back to the horizontal centre.
 */
export function fractionForOutput(node, output, opts = DEFAULT_OPTS) {
    const outs = outputsFor(node, opts);
    const idx = outs.findIndex((o) => o.handle === output);
    if (idx === -1 || !outs.length) return 0.5;
    return handleY(idx, outs.length);
}

/**
 * @param {Array}  nodes    [{ node_key, type, ... }]
 * @param {Array}  edges    [{ from_node_key, from_output, to_node_key }]
 * @param {Object} options  { branchTypes, terminalTypes }
 * @returns {{ positions: Object, openOutputs: Array, roots: Array }}
 *   positions:   { [node_key]: { x, y } }
 *   openOutputs: [{ from_node_key, from_output }] — outputs with no edge yet
 *                (these are where the append "+" adders are placed)
 *   roots:       node_keys with no incoming edge (top of the flow)
 */
export function computeLayout(nodes = [], edges = [], options = {}) {
    const opts = { ...DEFAULT_OPTS, ...options };
    const positions = {};
    if (!nodes.length) {
        return { positions, openOutputs: [], roots: [] };
    }

    const byKey = new Map(nodes.map((n) => [n.node_key, n]));
    const order = new Map(nodes.map((n, i) => [n.node_key, i]));

    // Outgoing edges grouped per source node; indegree per node.
    const childrenOf = new Map();
    const indegree = new Map(nodes.map((n) => [n.node_key, 0]));
    for (const e of edges) {
        if (!byKey.has(e.from_node_key) || !byKey.has(e.to_node_key)) continue;
        if (!childrenOf.has(e.from_node_key)) childrenOf.set(e.from_node_key, []);
        childrenOf.get(e.from_node_key).push({
            to: e.to_node_key,
            output: e.from_output || 'default',
        });
        indegree.set(e.to_node_key, (indegree.get(e.to_node_key) ?? 0) + 1);
    }

    // Children in a stable, output-driven order so branch true/false always map
    // to the same left/right columns.
    function orderedChildren(key) {
        const edgesOut = childrenOf.get(key) ?? [];
        const outs = outputsFor(byKey.get(key), opts);
        const seen = new Set();
        const result = [];
        const push = (to) => {
            if (to == null || seen.has(to)) return;
            seen.add(to);
            result.push(to);
        };
        for (const out of outs) {
            for (const edge of edgesOut) if (edge.output === out.handle) push(edge.to);
        }
        // Edges on unexpected outputs (legacy data) trail after.
        for (const edge of edgesOut) push(edge.to);
        return result;
    }

    // Roots = indegree 0 (a trigger has no incoming edge). Deterministic order.
    let roots = nodes
        .filter((n) => (indegree.get(n.node_key) ?? 0) === 0)
        .map((n) => n.node_key);
    if (!roots.length) roots = [nodes[0].node_key]; // fully cyclic fallback
    roots.sort((a, b) => order.get(a) - order.get(b));

    const placed = new Set();
    let cursor = LAYOUT.ORIGIN_X;

    // Tidy-tree: place a subtree left→right, return the node's centre x.
    function place(key, depth) {
        if (placed.has(key)) return positions[key]?.x ?? cursor;
        placed.add(key);

        const kids = orderedChildren(key).filter((k) => !placed.has(k));
        let centerX;
        if (!kids.length) {
            centerX = cursor;
            cursor += LAYOUT.COLUMN_SPAN;
        } else {
            const centers = kids.map((k) => place(k, depth + 1));
            centerX = (centers[0] + centers[centers.length - 1]) / 2;
        }
        positions[key] = {
            x: Math.round(centerX),
            y: LAYOUT.ORIGIN_Y + depth * LAYOUT.ROW_HEIGHT,
        };
        return centerX;
    }

    for (const root of roots) {
        place(root, 0);
        cursor += LAYOUT.COLUMN_SPAN; // gap between disconnected roots
    }
    // Any node not reached from a root (defensive) gets stacked at the end.
    for (const n of nodes) {
        if (!positions[n.node_key]) place(n.node_key, 0);
    }

    // Open outputs = append points ("+" adders).
    const hasEdgeFrom = new Set(
        edges.map((e) => `${e.from_node_key}::${e.from_output || 'default'}`),
    );
    const openOutputs = [];
    for (const n of nodes) {
        for (const out of outputsFor(n, opts)) {
            if (!hasEdgeFrom.has(`${n.node_key}::${out.handle}`)) {
                openOutputs.push({ from_node_key: n.node_key, from_output: out.handle, label: out.label });
            }
        }
    }

    return { positions, openOutputs, roots };
}
