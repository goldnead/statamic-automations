/**
 * Client-side flow validation (A3 — inline validation in the editor).
 *
 * The server's FlowValidator (src/Engine/FlowValidator.php) is the source of
 * truth for the full picture (trigger count, edges, cycles, required fields).
 * But it only runs when the user clicks "Validate". To mark invalid nodes and
 * fields *live* as the config changes, we mirror the required-field check here,
 * against the same node config schema the server exposes through the node
 * library (`schema` array on each library entry).
 *
 * These are pure functions so they can be unit-tested (tests/js) and reused
 * reactively from Edit.vue without pulling in Vue reactivity or the CP `__`
 * translator (which is unavailable in the node test runner).
 */

/**
 * Resolve the config schema (array of field descriptors) for a graph node from
 * the node library returned by the server, keyed by node `type`.
 *
 * @param {{type: string}} node
 * @param {{triggers?: Array, logic?: Array, actions?: Array}} library
 * @returns {Array<{handle?: string, required?: boolean, label?: string}>}
 */
export function schemaFor(node, library) {
    if (!node || !library) return [];
    const all = [
        ...(library.triggers ?? []),
        ...(library.logic ?? []),
        ...(library.actions ?? []),
    ];
    return all.find((m) => m.handle === node.type)?.schema ?? [];
}

/**
 * Mirror the server's emptiness test: a required field is missing when its
 * config value is absent, an empty string, or null. (Empty arrays/objects —
 * e.g. a key_value or conditions field — count as present, matching PHP's
 * `=== '' || === null` check.)
 */
export function isEmptyValue(value) {
    return value === undefined || value === null || value === '';
}

/**
 * The handles of every required field that is currently unset on `node`.
 *
 * @returns {string[]}
 */
export function missingRequiredHandles(node, library) {
    if (!node) return [];
    const config = node.config ?? {};
    return schemaFor(node, library)
        .filter((f) => f.required === true && f.handle && isEmptyValue(config[f.handle]))
        .map((f) => f.handle);
}

/**
 * Flat list of per-node validation issues, shaped like the server's issues so
 * the two can be merged in the UI:
 *   { node_key, field, code, level, message }
 *
 * @returns {Array<{node_key: string, field: string, code: string, level: string, message: string}>}
 */
export function computeNodeIssues(nodes, library) {
    const issues = [];
    for (const node of nodes ?? []) {
        for (const handle of missingRequiredHandles(node, library)) {
            issues.push({
                node_key: node.node_key,
                field: handle,
                code: 'missing_required_config',
                level: 'error',
                message: `Node '${node.node_key}' is missing required field '${handle}'.`,
            });
        }
    }
    return issues;
}
