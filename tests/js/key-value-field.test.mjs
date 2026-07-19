/**
 * Unit tests for the pure row <-> value transforms behind `KeyValueField.vue`
 * (Switch `cases`, Parallel `branches`, and every action's `data` /
 * `variables` / `headers` field). These functions are the load-bearing piece
 * of that editor: `toRows`/`rowsToObject` coerce whatever shape a config
 * value arrives in, and `duplicateKeyIndices` is what lets the UI surface a
 * duplicate key instead of silently dropping a mapping/output-edge via
 * last-write-wins.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    makeRow,
    toRows,
    rowsToObject,
    duplicateKeyIndices,
} from '../../resources/js/composables/useKeyValueRows.js';

test('object -> rows -> object round-trips the same map', () => {
    const original = { paid: 'true_branch', unpaid: 'false_branch' };
    const rows = toRows(original);
    assert.deepEqual(rowsToObject(rows), original);
});

test('rows preserve key and value through the round-trip', () => {
    const rows = toRows({ status: 'active' });
    assert.equal(rows.length, 1);
    assert.equal(rows[0].key, 'status');
    assert.equal(rows[0].value, 'active');
});

test('legacy JSON string input is coerced into rows', () => {
    const rows = toRows('{"a":"1","b":"2"}');
    assert.deepEqual(rowsToObject(rows), { a: '1', b: '2' });
});

test('blank string input coerces to no rows', () => {
    assert.deepEqual(toRows(''), []);
    assert.deepEqual(toRows('   '), []);
});

test('unparsable string input degrades to no rows instead of throwing', () => {
    assert.deepEqual(toRows('not json'), []);
});

test('legacy array-of-{key,value} pairs is coerced into rows', () => {
    const rows = toRows([{ key: 'a', value: '1' }, { key: 'b', value: '2' }]);
    assert.deepEqual(rowsToObject(rows), { a: '1', b: '2' });
});

test('legacy array-of-{handle,label} pairs is coerced into rows', () => {
    const rows = toRows([{ handle: 'true', label: 'Yes' }, { handle: 'false', label: 'No' }]);
    assert.deepEqual(rowsToObject(rows), { true: 'Yes', false: 'No' });
});

test('legacy array-of-[key, value] tuples is coerced into rows', () => {
    const rows = toRows([['a', '1'], ['b', '2']]);
    assert.deepEqual(rowsToObject(rows), { a: '1', b: '2' });
});

test('null/undefined input coerces to no rows', () => {
    assert.deepEqual(toRows(null), []);
    assert.deepEqual(toRows(undefined), []);
});

test('rowsToObject excludes empty-key rows', () => {
    const rows = [makeRow('', 'orphan value'), makeRow('real', 'kept')];
    assert.deepEqual(rowsToObject(rows), { real: 'kept' });
});

test('rowsToObject is last-write-wins on duplicate keys', () => {
    const rows = [makeRow('paid', 'first'), makeRow('paid', 'second')];
    assert.deepEqual(rowsToObject(rows), { paid: 'second' });
});

test('duplicateKeyIndices flags only the later row(s) sharing a key', () => {
    const rows = [makeRow('paid', 'a'), makeRow('unpaid', 'b'), makeRow('paid', 'c')];
    assert.deepEqual(duplicateKeyIndices(rows), [2]);
});

test('duplicateKeyIndices flags every repeat after the first occurrence', () => {
    const rows = [makeRow('x', '1'), makeRow('x', '2'), makeRow('x', '3')];
    assert.deepEqual(duplicateKeyIndices(rows), [1, 2]);
});

test('duplicateKeyIndices ignores empty-key rows entirely', () => {
    const rows = [makeRow('', '1'), makeRow('', '2'), makeRow('real', '3')];
    assert.deepEqual(duplicateKeyIndices(rows), []);
});

test('duplicateKeyIndices returns nothing when all keys are unique', () => {
    const rows = [makeRow('a', '1'), makeRow('b', '2'), makeRow('c', '3')];
    assert.deepEqual(duplicateKeyIndices(rows), []);
});
