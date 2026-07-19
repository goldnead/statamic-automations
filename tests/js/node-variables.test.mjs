/**
 * Unit tests for the upstream variable / token picker data layer.
 *
 * `useNodeVariables` powers the "{{ }}" TokenInserter: it walks the graph
 * backward from the selected node and turns each ancestor's `output_schema`
 * into insertable token strings. `buildSampleIndex` / `formatSampleValue`
 * layer *resolved* sample values from a (test) run's `node_runs` on top of
 * those tokens, so the picker can show `entry.title → "My Post"`.
 *
 * These are pure-data guarantees the UI relies on; they must stay green
 * independent of any DOM.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    useNodeVariables,
    buildSampleIndex,
    formatSampleValue,
} from '../../resources/js/composables/useNodeVariables.js';

// --- fixtures -------------------------------------------------------------

const library = {
    triggers: [
        {
            handle: 'entry_saved',
            label: 'Entry Saved',
            output_schema: { entry: { id: 'string', title: 'string' } },
        },
    ],
    logic: [],
    actions: [
        {
            handle: 'create_entry',
            label: 'Create Entry',
            output_schema: { entry: { id: 'string' } },
        },
        { handle: 'send_email', label: 'Send Email' }, // no output_schema
    ],
};

// trigger(t) -> create(c) -> send(s)
const nodes = [
    { node_key: 't', type: 'entry_saved', label: 'When entry saved' },
    { node_key: 'c', type: 'create_entry', label: 'Create draft' },
    { node_key: 's', type: 'send_email', label: 'Notify' },
];
const edges = [
    { from_node_key: 't', to_node_key: 'c' },
    { from_node_key: 'c', to_node_key: 's' },
];
const automation = { nodes, edges };

// --- useNodeVariables -----------------------------------------------------

test('collects trigger tokens unprefixed and node tokens prefixed', () => {
    const vars = useNodeVariables('s', automation, library).value;
    const tokens = vars.map((v) => v.token);

    // create_entry (nearest) then trigger (further) — nearest-upstream-first.
    assert.deepEqual(tokens, [
        '{{ nodes.c.entry.id }}',
        '{{ entry.id }}',
        '{{ entry.title }}',
    ]);
});

test('returns empty array when node has no ancestors', () => {
    assert.deepEqual(useNodeVariables('t', automation, library).value, []);
});

test('never throws on malformed / missing graph', () => {
    assert.deepEqual(useNodeVariables('x', { nodes: null, edges: null }, {}).value, []);
    assert.deepEqual(useNodeVariables(null, automation, library).value, []);
});

// --- formatSampleValue ----------------------------------------------------

test('formatSampleValue renders scalars and truncates long strings', () => {
    assert.equal(formatSampleValue('My Post'), 'My Post');
    assert.equal(formatSampleValue(42), '42');
    assert.equal(formatSampleValue(true), 'true');
    assert.equal(formatSampleValue(''), null); // blank → no sample
    assert.equal(formatSampleValue(null), null);
    assert.equal(formatSampleValue(undefined), null);

    const long = 'x'.repeat(80);
    const out = formatSampleValue(long);
    assert.ok(out.length <= 40);
    assert.ok(out.endsWith('…'));
});

test('formatSampleValue serialises arrays/objects compactly', () => {
    assert.equal(formatSampleValue([1, 2]), '[1,2]');
    assert.equal(formatSampleValue({ a: 1 }), '{"a":1}');
});

// --- buildSampleIndex -----------------------------------------------------

const run = {
    node_runs: [
        { node_key: 't', node_type: 'entry_saved', output: { entry: { id: '7', title: 'My Post' } } },
        { node_key: 'c', node_type: 'create_entry', output: { entry: { id: '99' } } },
        { node_key: 's', node_type: 'send_email', output: null },
    ],
};

test('buildSampleIndex maps run outputs onto the same token keys', () => {
    const index = buildSampleIndex(run, library);
    assert.equal(index['{{ entry.id }}'], '7');
    assert.equal(index['{{ entry.title }}'], 'My Post');
    assert.equal(index['{{ nodes.c.entry.id }}'], '99');
});

test('buildSampleIndex is empty for a missing/blank run', () => {
    assert.deepEqual(buildSampleIndex(null, library), {});
    assert.deepEqual(buildSampleIndex({}, library), {});
    assert.deepEqual(buildSampleIndex({ node_runs: [] }, library), {});
});

test('useNodeVariables attaches resolved sample values when a run is supplied', () => {
    const vars = useNodeVariables('s', automation, library, run).value;
    const byToken = Object.fromEntries(vars.map((v) => [v.token, v.sample]));

    assert.equal(byToken['{{ nodes.c.entry.id }}'], '99');
    assert.equal(byToken['{{ entry.id }}'], '7');
    assert.equal(byToken['{{ entry.title }}'], 'My Post');
});

test('useNodeVariables leaves sample null when no run context', () => {
    const vars = useNodeVariables('s', automation, library).value;
    assert.ok(vars.every((v) => v.sample === null));
});
