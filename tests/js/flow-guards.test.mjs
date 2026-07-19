/**
 * Unit tests for the builder's one-trigger-per-flow + pending-pick-target
 * guards (composables/useFlowGuards.js).
 *
 * These are the invariants a review found bypassed by `duplicateNode()` and
 * left unvalidated by `onLibraryPick()` (see Edit.vue): a trigger must never
 * be duplicable (it would create a second trigger with no Delete action to
 * recover from), and a pick-mode target armed against node_keys that get
 * deleted before the pick completes must not be inserted against.
 *
 * Run: `npm run test:js`
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    isTriggerHandle,
    hasTrigger,
    canInsert,
    canDuplicate,
    pendingTargetIsValid,
} from '../../resources/js/composables/useFlowGuards.js';

const library = {
    triggers: [{ handle: 'manual' }, { handle: 'scheduled' }],
    logic: [{ handle: 'branch' }, { handle: 'filter' }],
    actions: [{ handle: 'send_email' }],
};

const trigger = { node_key: 't', type: 'manual' };
const step = (key, type = 'send_email') => ({ node_key: key, type });

// ---------- isTriggerHandle / hasTrigger ----------

test('isTriggerHandle is true only for handles in library.triggers', () => {
    assert.equal(isTriggerHandle('manual', library), true);
    assert.equal(isTriggerHandle('scheduled', library), true);
    assert.equal(isTriggerHandle('branch', library), false);
    assert.equal(isTriggerHandle('send_email', library), false);
    assert.equal(isTriggerHandle('unknown', library), false);
});

test('hasTrigger reflects whether any node in the graph is a trigger', () => {
    assert.equal(hasTrigger([], library), false);
    assert.equal(hasTrigger([step('a')], library), false);
    assert.equal(hasTrigger([trigger, step('a')], library), true);
});

// ---------- canInsert (adding a node) ----------

test('adding a trigger to an empty flow is allowed', () => {
    const { ok } = canInsert('manual', library, []);
    assert.equal(ok, true);
});

test('adding a 2nd trigger is refused', () => {
    const { ok, reason } = canInsert('scheduled', library, [trigger]);
    assert.equal(ok, false);
    assert.equal(reason, 'one-trigger');
});

test('inserting logic is always allowed, even alongside an existing trigger', () => {
    const { ok } = canInsert('branch', library, [trigger]);
    assert.equal(ok, true);
});

test('inserting an action is always allowed, even alongside an existing trigger', () => {
    const { ok } = canInsert('send_email', library, [trigger, step('a')]);
    assert.equal(ok, true);
});

// ---------- canDuplicate ----------

test('duplicating a trigger is refused', () => {
    const { ok, reason } = canDuplicate(trigger, library, [trigger]);
    assert.equal(ok, false);
    assert.equal(reason, 'one-trigger');
});

test('duplicating a non-trigger is allowed', () => {
    const { ok } = canDuplicate(step('a'), library, [trigger, step('a')]);
    assert.equal(ok, true);
});

test('duplicating a node that no longer exists is refused', () => {
    const { ok, reason } = canDuplicate(null, library, [trigger]);
    assert.equal(ok, false);
    assert.equal(reason, 'not-found');
});

// ---------- pendingTargetIsValid ----------

test('a null pending target is trivially valid (nothing to insert against)', () => {
    assert.equal(pendingTargetIsValid(null, [trigger]), true);
});

test('a root append target (fromNodeKey: null) is always valid', () => {
    const target = { kind: 'append', fromNodeKey: null, output: 'default' };
    assert.equal(pendingTargetIsValid(target, []), true);
});

test('an append target referencing a live node is valid', () => {
    const target = { kind: 'append', fromNodeKey: 't', output: 'default' };
    assert.equal(pendingTargetIsValid(target, [trigger]), true);
});

test('an append target is invalid when a referenced node_key is gone', () => {
    const target = { kind: 'append', fromNodeKey: 'deleted', output: 'default' };
    assert.equal(pendingTargetIsValid(target, [trigger]), false);
});

test('an insert-on-edge target is valid only while both endpoints still exist', () => {
    const nodes = [trigger, step('a')];
    const validTarget = {
        kind: 'insert',
        edge: { from_node_key: 't', from_output: 'default', to_node_key: 'a' },
    };
    assert.equal(pendingTargetIsValid(validTarget, nodes), true);

    const staleFrom = {
        kind: 'insert',
        edge: { from_node_key: 'deleted', from_output: 'default', to_node_key: 'a' },
    };
    assert.equal(pendingTargetIsValid(staleFrom, nodes), false);

    const staleTo = {
        kind: 'insert',
        edge: { from_node_key: 't', from_output: 'default', to_node_key: 'deleted' },
    };
    assert.equal(pendingTargetIsValid(staleTo, nodes), false);
});

test('a replace-trigger target is invalid once its trigger node is deleted', () => {
    const target = { kind: 'replace', nodeKey: 't' };
    assert.equal(pendingTargetIsValid(target, [trigger]), true);
    assert.equal(pendingTargetIsValid(target, [step('a')]), false);
});
