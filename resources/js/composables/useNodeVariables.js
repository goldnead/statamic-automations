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
 * @returns {import('vue').ComputedRef<Array<{token: string, label: string, source: string}>>}
 *   Nearest-upstream-first, deduped by token. Empty array (never throws)
 *   when the node has no reachable ancestors or none expose variables.
 */
export function useNodeVariables(nodeId, automation, library) {
    return computed(() => {
        const currentKey = resolve(nodeId);
        const graph = resolve(automation) ?? {};
        const lib = resolve(library) ?? {};
        const nodes = Array.isArray(graph.nodes) ? graph.nodes : [];
        const edges = Array.isArray(graph.edges) ? graph.edges : [];

        if (!currentKey || !nodes.length) return [];

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

                variables.push({ token, label: humanizePath(path), source: sourceLabel });
            }
        }

        return variables;
    });
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
