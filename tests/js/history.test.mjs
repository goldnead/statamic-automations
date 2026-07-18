/**
 * Guards the builder's undo/redo history stack (composables/useHistory.js).
 *
 * Exercises the composable exactly as Edit.vue drives it: mutate a plain graph
 * object, call `record()` after each operation, then assert that `undo()`
 * restores the prior graph and `redo()` re-applies it.
 *
 * Run: `npm run test:js`
 */
import assert from 'node:assert/strict';
import test from 'node:test';

const { useHistory } = await import('../../resources/js/composables/useHistory.js');

function makeHarness(initial = { nodes: [], edges: [] }) {
    let state = JSON.parse(JSON.stringify(initial));
    const history = useHistory({
        getState: () => state,
        setState: (next) => { state = next; },
    });
    return {
        history,
        get: () => state,
        set: (next) => { state = JSON.parse(JSON.stringify(next)); },
    };
}

test('a fresh history cannot undo or redo', () => {
    const { history } = makeHarness();
    assert.equal(history.canUndo.value, false);
    assert.equal(history.canRedo.value, false);
});

test('undo restores the previous graph, redo re-applies it', () => {
    const h = makeHarness({ nodes: [], edges: [] });

    // Op 1: add a node.
    h.set({ nodes: [{ node_key: 'a', type: 'manual' }], edges: [] });
    h.history.record();
    assert.equal(h.history.canUndo.value, true);
    assert.equal(h.get().nodes.length, 1);

    // Op 2: connect an edge to a second node.
    h.set({
        nodes: [{ node_key: 'a', type: 'manual' }, { node_key: 'b', type: 'send_email' }],
        edges: [{ from_node_key: 'a', to_node_key: 'b' }],
    });
    h.history.record();
    assert.equal(h.get().nodes.length, 2);
    assert.equal(h.get().edges.length, 1);

    // Undo → back to the single-node state.
    h.history.undo();
    assert.equal(h.get().nodes.length, 1);
    assert.equal(h.get().edges.length, 0);
    assert.equal(h.history.canRedo.value, true);

    // Undo again → back to empty.
    h.history.undo();
    assert.equal(h.get().nodes.length, 0);
    assert.equal(h.history.canUndo.value, false);

    // Redo twice → forward to the two-node state.
    h.history.redo();
    assert.equal(h.get().nodes.length, 1);
    h.history.redo();
    assert.equal(h.get().nodes.length, 2);
    assert.equal(h.get().edges.length, 1);
    assert.equal(h.history.canRedo.value, false);
});

test('a new operation after undo clears the redo stack', () => {
    const h = makeHarness({ nodes: [], edges: [] });
    h.set({ nodes: [{ node_key: 'a' }], edges: [] });
    h.history.record();
    h.history.undo();
    assert.equal(h.history.canRedo.value, true);

    // Diverge: record a different branch.
    h.set({ nodes: [{ node_key: 'z' }], edges: [] });
    h.history.record();
    assert.equal(h.history.canRedo.value, false);
});

test('record() ignores no-op mutations', () => {
    const h = makeHarness({ nodes: [{ node_key: 'a' }], edges: [] });
    h.history.record(); // identical snapshot → no history entry
    assert.equal(h.history.canUndo.value, false);
});

test('restored state is a deep clone (mutating the graph does not corrupt history)', () => {
    const h = makeHarness({ nodes: [], edges: [] });
    h.set({ nodes: [{ node_key: 'a', config: { x: 1 } }], edges: [] });
    h.history.record();
    h.history.undo();

    // Mutate the (empty) restored state in place, then redo.
    h.get().nodes.push({ node_key: 'tmp' });
    h.history.redo();
    assert.equal(h.get().nodes.length, 1);
    assert.equal(h.get().nodes[0].node_key, 'a');
});
