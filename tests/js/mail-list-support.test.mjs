/**
 * The pure half of the mail list view.
 *
 * The classifier is the part that has to keep working when nobody is looking:
 * it turns the linearity rule's prose back into the numbered condition it came
 * from, and that is the whole difference between telling an editor "not linear"
 * and telling them which of the seven conditions to go and fix. The sentences
 * asserted here are the ones `Sequence\LinearityRule` actually emits — if that
 * wording ever changes, this suite is the thing that notices.
 *
 * Run: `npm run test:js`
 */
import assert from 'node:assert/strict';
import test from 'node:test';

import {
    classifyReason,
    classifyReasons,
    durationParts,
    movedOrder,
} from '../../resources/js/support/mailList.js';

test('every reason the rule emits maps onto the condition it came from', () => {
    const cases = [
        ['The automation has no trigger node.', 1],
        ['The automation has 2 trigger nodes; a list can only be edited when there is exactly one.', 1],
        ["Node 'a' has 2 outgoing edges.", 2],
        ["Node 'a' has 2 incoming edges, so more than one path leads to it.", 3],
        ["Node 'br' has an edge on the 'true' output, so the flow forks.", 4],
        ["Node 'br' is a branch node, which forks the flow by nature.", 5],
        ['These nodes cannot be reached from the trigger: x, y.', 6],
        ['The chain loops back on itself.', 7],
    ];

    for (const [reason, condition] of cases) {
        assert.equal(classifyReason(reason), condition, reason);
    }
});

test('a sentence nobody anticipated classifies as null rather than as a wrong condition', () => {
    assert.equal(classifyReason('Something entirely new happened.'), null);
});

test('reasons group by condition, lowest first, keeping every sentence', () => {
    const groups = classifyReasons([
        'The chain loops back on itself.',
        "Node 'a' has 2 outgoing edges.",
        "Node 'b' has 2 outgoing edges.",
        'Something entirely new happened.',
    ]);

    assert.deepEqual(groups.map((g) => g.condition), [2, 7, null]);
    assert.equal(groups[0].details.length, 2);
    // The unclassified one is last and is still carried through verbatim.
    assert.deepEqual(groups[2].details, ['Something entirely new happened.']);
});

test('a gap is broken into the units a person reads it in', () => {
    assert.deepEqual(durationParts(2 * 86400), [{ value: 2, unit: 'day' }]);
    assert.deepEqual(durationParts(90000), [
        { value: 1, unit: 'day' },
        { value: 1, unit: 'hour' },
    ]);
    assert.deepEqual(durationParts(45 * 60), [{ value: 45, unit: 'minute' }]);
    // Zero is "immediately", which is a sentence, not a duration.
    assert.deepEqual(durationParts(0), []);
});

test('a move produces the whole order, because that is what the endpoint wants', () => {
    assert.deepEqual(movedOrder(['a', 'b', 'c'], 2, -1), ['a', 'c', 'b']);
    assert.deepEqual(movedOrder(['a', 'b', 'c'], 0, 1), ['b', 'a', 'c']);
});

test('a move off either end is refused rather than silently clamped', () => {
    assert.equal(movedOrder(['a', 'b'], 0, -1), null);
    assert.equal(movedOrder(['a', 'b'], 1, 1), null);
});
