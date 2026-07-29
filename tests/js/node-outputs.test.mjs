/**
 * The canvas half of the node-output contract.
 *
 * Every case here resolves a spec the PHP registry actually produced
 * (`fixtures/node-output-specs.json`, written by
 * tests/Feature/NodeOutputSpecContractTest.php) and asserts the handles the
 * server-side resolver produces for the same config. The two resolvers are in
 * different languages and neither suite can call the other, so this file and
 * that one are the seam.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    OUTPUT_SPEC_VERSION,
    clearNodeOutputSpecs,
    continuationOutput,
    outputsFor,
    resolveOutputSpec,
    setNodeOutputSpecs,
} from '../../resources/js/composables/useNodeOutputs.js';
import { specs, useBuiltInOutputs } from './fixtures/built-in-library.mjs';

const handles = (node) => outputsFor(node).map((o) => o.handle);

test('a node type with no registered spec keeps the single default continuation', () => {
    clearNodeOutputSpecs();
    assert.deepEqual(outputsFor({ type: 'acme.whatever' }), [{ handle: 'default', label: '' }]);
    assert.deepEqual(outputsFor({ type: 'branch' }), [{ handle: 'default', label: '' }]);
});

test('the built-in specs resolve to the handles the engine routes on', () => {
    useBuiltInOutputs();

    assert.deepEqual(outputsFor({ type: 'branch' }), [
        { handle: 'true', label: 'True' },
        { handle: 'false', label: 'False' },
    ]);
    assert.deepEqual(handles({ type: 'loop' }), ['loop', 'done']);
    assert.deepEqual(outputsFor({ type: 'stop' }), []);
    assert.deepEqual(outputsFor({ type: 'send_email' }), [{ handle: 'default', label: '' }]);
});

test('switch outputs follow the cases the user is typing', () => {
    useBuiltInOutputs();

    // handle = the output typed on the right, label = the value matched on
    // the left. Same order, same dedupe as SwitchNode::outputs() in PHP.
    assert.deepEqual(outputsFor({ type: 'switch', config: { cases: { de: 'german', en: 'english' } } }), [
        { handle: 'german', label: 'de' },
        { handle: 'english', label: 'en' },
        { handle: 'default', label: 'Default' },
    ]);

    // A case with no handle typed routes to default, and the trailing default
    // is not added twice.
    assert.deepEqual(handles({ type: 'switch', config: { cases: { a: '' } } }), ['default']);
    assert.deepEqual(handles({ type: 'switch', config: {} }), ['default']);

    // The shapes a key_value field arrives in mid-edit.
    assert.deepEqual(
        handles({ type: 'switch', config: { cases: [{ key: 'a', value: 'alpha' }] } }),
        ['alpha', 'default'],
    );
    assert.deepEqual(handles({ type: 'switch', config: { cases: '{"a":"alpha"}' } }), ['alpha', 'default']);
});

test('parallel outputs follow its mode and its branches', () => {
    useBuiltInOutputs();

    assert.deepEqual(outputsFor({ type: 'parallel', config: { branches: { a: 'Alpha', b: '' } } }), [
        { handle: 'a', label: 'Alpha' },
        { handle: 'b', label: 'b' },
    ]);

    // Legacy automation mode: the branches are sub-runs, not graph edges.
    assert.deepEqual(handles({ type: 'parallel', config: { mode: 'automation', branches: { a: 'x' } } }), ['default']);

    // Nothing configured yet — no outputs, never an invented one.
    assert.deepEqual(outputsFor({ type: 'parallel', config: {} }), []);
});

test('the loop names its continuation, a branch does not', () => {
    useBuiltInOutputs();

    // The whole point of `primary`: "after loop", not "into the body".
    assert.equal(continuationOutput({ type: 'loop' }), 'done');
    assert.equal(continuationOutput({ type: 'branch' }), 'true');
    assert.equal(continuationOutput({ type: 'send_email' }), 'default');
    assert.equal(continuationOutput({ type: 'stop' }), null);
    assert.equal(continuationOutput({ type: 'parallel', config: {} }), null);
});

test('a third-party node gets exactly the handles it declares', () => {
    // The promise this release exists for. Nothing about `acme.review` is
    // known to the canvas beyond what its spec says.
    useBuiltInOutputs({
        'acme.review': {
            version: 1,
            clauses: [{
                outputs: [
                    { handle: 'approved', label: 'Approved' },
                    { handle: 'rejected', label: 'Rejected' },
                    { handle: 'escalated', label: 'Escalated' },
                ],
            }],
            primary: 'approved',
        },
    });

    assert.deepEqual(handles({ type: 'acme.review' }), ['approved', 'rejected', 'escalated']);
    assert.equal(continuationOutput({ type: 'acme.review' }), 'approved');
});

test('a third-party branch may declare something other than true/false', () => {
    // The `.branch` suffix is a fallback for a type that declares nothing
    // (that is all 1.5.5 could offer it), not a cap on what it may declare.
    useBuiltInOutputs({
        'acme.branch': { version: 1, clauses: [{ outputs: ['yes', 'no', 'unknown'] }] },
    });

    assert.deepEqual(handles({ type: 'acme.branch' }), ['yes', 'no', 'unknown']);
});

test('a spec from a newer contract falls back rather than being guessed at', () => {
    // An older published canvas meeting a newer server. Fields it does not
    // know could mean anything; one `default` output is what it did for every
    // unknown node before specs existed, and it keeps every stored edge.
    setNodeOutputSpecs([
        { handle: 'acme.future', outputs: { version: OUTPUT_SPEC_VERSION + 1, clauses: [{ outputs: ['a', 'b'] }] } },
    ]);

    assert.deepEqual(outputsFor({ type: 'acme.future' }), [{ handle: 'default', label: '' }]);
});

test('a malformed or empty spec never throws', () => {
    assert.deepEqual(resolveOutputSpec(null), []);
    assert.deepEqual(resolveOutputSpec({}), []);
    assert.deepEqual(resolveOutputSpec({ version: 1, clauses: [] }), []);
    // No clause matches: the node has no outputs, not a guessed one.
    assert.deepEqual(
        resolveOutputSpec({ version: 1, clauses: [{ when: { field: 'mode', is: ['x'] }, outputs: ['a'] }] }, {}),
        [],
    );
});

test('the fixture is the specs the PHP registry ships', () => {
    // Guards the fixture itself: if somebody hand-edits it, the shape checks
    // here fail before the resolution tests silently start proving nothing.
    for (const [handle, spec] of Object.entries(specs)) {
        assert.equal(spec.version, OUTPUT_SPEC_VERSION, `${handle} spec version`);
        assert.ok(Array.isArray(spec.clauses), `${handle} clauses`);
    }
    assert.equal(specs.loop.primary, 'done');
    assert.equal(specs.branch.primary, undefined);
});
