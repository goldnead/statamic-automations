/**
 * Unit tests for the graph → position auto-layout.
 *
 * The builder derives every node position from the flow structure (no stored
 * coordinates), so this pure function is the load-bearing piece of the
 * fixed-layout / "+"-insert model. These tests pin the guarantees the canvas
 * relies on: vertical stacking, branch columns, and open-output detection.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { computeLayout, outputsFor, LAYOUT } from '../../resources/js/composables/useAutoLayout.js';

const trigger = { node_key: 't', type: 'manual' };
const step = (key, type = 'send_email') => ({ node_key: key, type });
const edge = (from, to, out = 'default') => ({ from_node_key: from, from_output: out, to_node_key: to });

test('empty graph yields no positions and no append points', () => {
    const { positions, openOutputs, roots } = computeLayout([], []);
    assert.deepEqual(positions, {});
    assert.deepEqual(openOutputs, []);
    assert.deepEqual(roots, []);
});

test('a lone node is a root with one open default output', () => {
    const { positions, openOutputs, roots } = computeLayout([trigger], []);
    assert.deepEqual(roots, ['t']);
    assert.equal(positions.t.y, LAYOUT.ORIGIN_Y);
    assert.deepEqual(openOutputs, [{ from_node_key: 't', from_output: 'default', label: '' }]);
});

test('linear chain stacks vertically in one centred column', () => {
    const nodes = [trigger, step('a'), step('b')];
    const edges = [edge('t', 'a'), edge('a', 'b')];
    const { positions, openOutputs } = computeLayout(nodes, edges);

    // Same column (x) …
    assert.equal(positions.t.x, positions.a.x);
    assert.equal(positions.a.x, positions.b.x);
    // … increasing depth (y).
    assert.equal(positions.a.y - positions.t.y, LAYOUT.ROW_HEIGHT);
    assert.equal(positions.b.y - positions.a.y, LAYOUT.ROW_HEIGHT);
    // Only the tail leaf has an open output.
    assert.deepEqual(openOutputs, [{ from_node_key: 'b', from_output: 'default', label: '' }]);
});

test('branch node fans true/false into two columns and is centred', () => {
    const nodes = [trigger, { node_key: 'br', type: 'branch' }, step('yes'), step('no')];
    const edges = [edge('t', 'br'), edge('br', 'yes', 'true'), edge('br', 'no', 'false')];
    const { positions, openOutputs } = computeLayout(nodes, edges);

    // The two branch children live on different columns …
    assert.notEqual(positions.yes.x, positions.no.x);
    // … "true" left of "false" (fixed column order) …
    assert.ok(positions.yes.x < positions.no.x);
    // … one row below the branch …
    assert.equal(positions.yes.y - positions.br.y, LAYOUT.ROW_HEIGHT);
    // … and the branch sits centred between them.
    assert.equal(positions.br.x, (positions.yes.x + positions.no.x) / 2);

    // Both branch leaves expose an open default output; the branch itself has none.
    const keys = openOutputs.map((o) => `${o.from_node_key}:${o.from_output}`).sort();
    assert.deepEqual(keys, ['no:default', 'yes:default']);
});

test('an unconnected branch side is reported as an open output', () => {
    const nodes = [{ node_key: 'br', type: 'branch' }, step('yes')];
    const edges = [edge('br', 'yes', 'true')];
    const { openOutputs } = computeLayout(nodes, edges);
    const branchOpen = openOutputs.filter((o) => o.from_node_key === 'br');
    assert.deepEqual(branchOpen, [{ from_node_key: 'br', from_output: 'false', label: 'False' }]);
});

test('terminal (stop) node exposes no output', () => {
    // outputsFor returns { handle, label } objects (labels drive the handle pills).
    assert.deepEqual(outputsFor({ type: 'stop' }), []);
    assert.deepEqual(outputsFor({ type: 'branch' }), [
        { handle: 'true', label: 'True' },
        { handle: 'false', label: 'False' },
    ]);
    assert.deepEqual(outputsFor({ type: 'send_email' }), [{ handle: 'default', label: '' }]);
});

test('cyclic data does not hang and still places every node', () => {
    const nodes = [step('a'), step('b')];
    const edges = [edge('a', 'b'), edge('b', 'a')];
    const { positions } = computeLayout(nodes, edges);
    assert.ok(positions.a && positions.b);
});
