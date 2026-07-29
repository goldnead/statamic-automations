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

import { outputsFor } from './useNodeOutputs.js';

/**
 * Re-exported so the layout's callers (Canvas, NodeCard, the graph mutations)
 * keep importing the outputs from the module that lays them out. The rule
 * itself moved to `useNodeOutputs.js` in 1.7.0: it is no longer the canvas's
 * to know.
 */
export { outputsFor };

export const LAYOUT = {
    NODE_WIDTH: 240,
    COLUMN_SPAN: 320, // horizontal distance between sibling branch columns
    ROW_HEIGHT: 200, // vertical distance between depth levels
    ORIGIN_X: 0,
    ORIGIN_Y: 0,
};

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
export function fractionForOutput(node, output) {
    const outs = outputsFor(node);
    const idx = outs.findIndex((o) => o.handle === output);
    if (idx === -1 || !outs.length) return 0.5;
    return handleY(idx, outs.length);
}

/**
 * Which handles each node has comes from the node itself (see
 * useNodeOutputs.js) — the layout no longer carries a `branchTypes` /
 * `terminalTypes` option list, because it no longer knows a node type by name.
 *
 * @param {Array}  nodes    [{ node_key, type, config, ... }]
 * @param {Array}  edges    [{ from_node_key, from_output, to_node_key }]
 * @returns {{ positions: Object, openOutputs: Array, roots: Array }}
 *   positions:   { [node_key]: { x, y } }
 *   openOutputs: [{ from_node_key, from_output }] — outputs with no edge yet
 *                (these are where the append "+" adders are placed)
 *   roots:       node_keys with no incoming edge (top of the flow)
 */
export function computeLayout(nodes = [], edges = []) {
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
        const outs = outputsFor(byKey.get(key));
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
        for (const out of outputsFor(n)) {
            if (!hasEdgeFrom.has(`${n.node_key}::${out.handle}`)) {
                openOutputs.push({ from_node_key: n.node_key, from_output: out.handle, label: out.label });
            }
        }
    }

    return { positions, openOutputs, roots };
}
