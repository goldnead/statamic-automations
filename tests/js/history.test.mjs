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

function makeHarness(initial = { nodes: [], edges: [] }, options = {}) {
    let state = JSON.parse(JSON.stringify(initial));
    const history = useHistory({
        getState: () => state,
        setState: (next) => { state = next; },
        ...options,
    });
    return {
        history,
        get: () => state,
        set: (next) => { state = JSON.parse(JSON.stringify(next)); },
    };
}

/** A harness whose clock only moves when the test moves it. */
function makeClockHarness(initial = { nodes: [], edges: [] }, coalesceMs = 600) {
    let at = 1_000;
    const h = makeHarness(initial, { coalesceMs, now: () => at });
    h.advance = (ms) => { at += ms; };
    return h;
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

// ---------------------------------------------------------------------------
// Coalescing: `record(tag)`.
//
// The builder's node label and node config are text fields — every keystroke
// reaches record(). One snapshot per keystroke means ~100 typed characters
// evict every structural step from the 100-entry stack, and the delete the
// user is reaching for is no longer in it.
// ---------------------------------------------------------------------------

const label = (key) => `label:${key}`;

test('a burst of tagged edits costs one undo step', () => {
    const h = makeClockHarness({ nodes: [{ node_key: 'a', label: '' }], edges: [] });

    for (const text of ['W', 'We', 'Wel', 'Welcome']) {
        h.set({ nodes: [{ node_key: 'a', label: text }], edges: [] });
        h.advance(40);
        h.history.record(label('a'));
    }

    h.history.undo();
    assert.equal(h.get().nodes[0].label, '');
    assert.equal(h.history.canUndo.value, false);
});

test('a structural step in front of the typing survives it', () => {
    const h = makeClockHarness({ nodes: [{ node_key: 'a' }, { node_key: 'b', label: '' }], edges: [] });

    // Delete a node (untagged), then type a name on the other one.
    h.set({ nodes: [{ node_key: 'b', label: '' }], edges: [] });
    h.history.record();

    for (const text of ['x', 'xy', 'xyz']) {
        h.set({ nodes: [{ node_key: 'b', label: text }], edges: [] });
        h.advance(40);
        h.history.record(label('b'));
    }

    h.history.undo();
    assert.equal(h.get().nodes[0].label, '');

    h.history.undo();
    assert.equal(h.get().nodes.length, 2);
    assert.equal(h.get().nodes[0].node_key, 'a');
});

test('a pause longer than the window starts a new step', () => {
    const h = makeClockHarness({ nodes: [{ node_key: 'a', label: '' }], edges: [] });

    h.set({ nodes: [{ node_key: 'a', label: 'one' }], edges: [] });
    h.history.record(label('a'));

    h.advance(601);
    h.set({ nodes: [{ node_key: 'a', label: 'one two' }], edges: [] });
    h.history.record(label('a'));

    h.history.undo();
    assert.equal(h.get().nodes[0].label, 'one');
});

test('a different tag starts a new step even without a pause', () => {
    const h = makeClockHarness({ nodes: [{ node_key: 'a', label: '' }, { node_key: 'b', label: '' }], edges: [] });

    h.set({ nodes: [{ node_key: 'a', label: 'one' }, { node_key: 'b', label: '' }], edges: [] });
    h.history.record(label('a'));
    h.set({ nodes: [{ node_key: 'a', label: 'one' }, { node_key: 'b', label: 'two' }], edges: [] });
    h.history.record(label('b'));

    h.history.undo();
    assert.equal(h.get().nodes[1].label, '');
    assert.equal(h.get().nodes[0].label, 'one');
});

test('two untagged records are never folded together', () => {
    const h = makeClockHarness({ nodes: [], edges: [] });

    h.set({ nodes: [{ node_key: 'a' }], edges: [] });
    h.history.record();
    h.set({ nodes: [{ node_key: 'a' }, { node_key: 'b' }], edges: [] });
    h.history.record();

    h.history.undo();
    assert.equal(h.get().nodes.length, 1);
});

test('undo ends the run, so later typing cannot fold into it', () => {
    const h = makeClockHarness({ nodes: [{ node_key: 'a', label: '' }], edges: [] });

    h.set({ nodes: [{ node_key: 'a', label: 'one' }], edges: [] });
    h.history.record(label('a'));
    h.set({ nodes: [{ node_key: 'a', label: 'one two' }], edges: [] });
    h.history.record(label('a'));

    h.history.undo();
    assert.equal(h.get().nodes[0].label, '');

    // Same tag, same instant — but the run was broken by the undo.
    h.set({ nodes: [{ node_key: 'a', label: 'three' }], edges: [] });
    h.history.record(label('a'));
    h.history.undo();
    assert.equal(h.get().nodes[0].label, '');
});

test('reset() drops every undo step, which is what a save does', () => {
    const h = makeHarness({ nodes: [], edges: [] });

    h.set({ nodes: [{ node_key: 'a' }], edges: [] });
    h.history.record();
    assert.equal(h.history.canUndo.value, true);

    h.history.reset();
    assert.equal(h.history.canUndo.value, false);
    assert.equal(h.history.canRedo.value, false);

    // And the new baseline is the saved graph, not the one before it.
    h.set({ nodes: [{ node_key: 'a' }, { node_key: 'b' }], edges: [] });
    h.history.record();
    h.history.undo();
    assert.equal(h.get().nodes.length, 1);
});
