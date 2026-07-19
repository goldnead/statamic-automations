/**
 * Pure guard logic for the builder's one-trigger-per-flow invariant and the
 * pick-mode pending-target lifecycle.
 *
 * Extracted from Edit.vue so the invariants that keep a flow valid (exactly
 * one trigger, no orphaned inserts against a target whose node was deleted
 * meanwhile) are testable without mounting the Vue component. Every export
 * here is a plain function over plain data — no reactive state, no Vue
 * imports — so it can be exercised directly from `node --test`.
 *
 * `library` is the same `{ triggers, logic, actions }` shape the page
 * receives as a prop; a node/handle's "kind" is inferred by which array it
 * lives in (there is no `kind` field on the node/handle itself).
 */

// Kind is inferred by which library array the handle lives in — mirrors
// Edit.vue's original isTriggerHandle() and Canvas.vue's nodeKind().
export function isTriggerHandle(handle, library) {
    return (library?.triggers ?? []).some((m) => m.handle === handle);
}

export function hasTrigger(nodes, library) {
    return (nodes ?? []).some((n) => isTriggerHandle(n.type, library));
}

// Can `handle` be inserted into `nodes` as a new node? Enforces the
// one-trigger rule: a trigger handle is only insertable while the flow has
// none yet. Non-trigger handles (logic/action) are always insertable.
export function canInsert(handle, library, nodes) {
    if (isTriggerHandle(handle, library) && hasTrigger(nodes, library)) {
        return { ok: false, reason: 'one-trigger' };
    }
    return { ok: true, reason: null };
}

// Can `node` (an existing graph node, e.g. the duplicate source) be
// duplicated? A trigger node must never be duplicable — the copy would be a
// second trigger, and a trigger has no Delete action to recover from that
// (see Edit.vue's removeNode / NodeCard.vue's dropdown), so it must be
// refused at the source.
export function canDuplicate(node, library, nodes) {
    if (!node) return { ok: false, reason: 'not-found' };
    if (isTriggerHandle(node.type, library)) {
        return { ok: false, reason: 'one-trigger' };
    }
    return { ok: true, reason: null };
}

// Does every node_key a pending pick-mode target references still exist in
// `nodes`? Pick mode can stay armed across other edits (delete, undo, …), so
// by the time a library pick completes, the target's `fromNodeKey` /
// `edge.from_node_key` / `edge.to_node_key` / `nodeKey` may have been deleted
// meanwhile — inserting against it would produce an orphaned/corrupt edge.
// A root append (`fromNodeKey: null`) has nothing to reference and is always
// valid; a missing/`null` target itself is treated as valid (nothing to
// insert against, so nothing to reject).
export function pendingTargetIsValid(pendingTarget, nodes) {
    if (!pendingTarget) return true;
    const keys = new Set((nodes ?? []).map((n) => n.node_key));

    if (pendingTarget.kind === 'replace') {
        return keys.has(pendingTarget.nodeKey);
    }
    if (pendingTarget.kind === 'append') {
        if (pendingTarget.fromNodeKey == null) return true;
        return keys.has(pendingTarget.fromNodeKey);
    }
    if (pendingTarget.kind === 'insert') {
        const edge = pendingTarget.edge;
        if (!edge) return false;
        return keys.has(edge.from_node_key) && keys.has(edge.to_node_key);
    }
    return true;
}
