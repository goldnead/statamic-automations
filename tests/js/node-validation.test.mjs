/**
 * Unit tests for the client-side inline validation (A3).
 *
 * These pin the required-field checking that marks nodes/fields invalid live
 * on the canvas, mirroring the server FlowValidator's `missing_required_config`
 * rule so the two agree.
 *
 * Run: `npm run test:js`
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    schemaFor,
    isEmptyValue,
    missingRequiredHandles,
    computeNodeIssues,
    defaultConfigForSchema,
} from '../../resources/js/composables/useNodeValidation.js';

// send_email requires to/subject/body; `template`/`reply_to`/`from` optional.
const library = {
    triggers: [{ handle: 'manual', schema: [] }],
    logic: [{ handle: 'branch', schema: [] }],
    actions: [
        {
            handle: 'send_email',
            schema: [
                { handle: 'to', label: 'To', required: true },
                { handle: 'subject', label: 'Subject', required: true },
                { handle: 'body', label: 'Body', required: true },
                { handle: 'from', label: 'From', required: false },
            ],
        },
    ],
};

test('isEmptyValue treats undefined, null and "" as empty, other values as present', () => {
    assert.equal(isEmptyValue(undefined), true);
    assert.equal(isEmptyValue(null), true);
    assert.equal(isEmptyValue(''), true);
    assert.equal(isEmptyValue('x'), false);
    assert.equal(isEmptyValue(0), false);
    assert.equal(isEmptyValue([]), false); // empty array counts as present (server parity)
});

test('schemaFor resolves the schema by node type from any library group', () => {
    assert.equal(schemaFor({ type: 'send_email' }, library).length, 4);
    assert.deepEqual(schemaFor({ type: 'unknown' }, library), []);
});

test('missingRequiredHandles lists every unset required field', () => {
    const node = { node_key: 'm', type: 'send_email', config: {} };
    assert.deepEqual(missingRequiredHandles(node, library), ['to', 'subject', 'body']);
});

test('a filled required field is no longer reported; optional fields never are', () => {
    const node = {
        node_key: 'm',
        type: 'send_email',
        config: { to: 'a@b.c', subject: 'Hi', body: 'Body', from: '' },
    };
    assert.deepEqual(missingRequiredHandles(node, library), []);
});

test('whitespace/empty-string required field is still missing', () => {
    const node = { node_key: 'm', type: 'send_email', config: { to: '', subject: 'S', body: 'B' } };
    assert.deepEqual(missingRequiredHandles(node, library), ['to']);
});

test('computeNodeIssues emits one issue per missing field, shaped like server issues', () => {
    const nodes = [
        { node_key: 't', type: 'manual', config: {} },
        { node_key: 'm', type: 'send_email', config: { to: 'a@b.c' } },
    ];
    const issues = computeNodeIssues(nodes, library);
    assert.equal(issues.length, 2); // subject + body
    for (const issue of issues) {
        assert.equal(issue.node_key, 'm');
        assert.equal(issue.code, 'missing_required_config');
        assert.equal(issue.level, 'error');
        assert.ok(['subject', 'body'].includes(issue.field));
        assert.match(issue.message, /missing required field/);
    }
});

test('a fully valid graph yields no issues', () => {
    const nodes = [
        { node_key: 't', type: 'manual', config: {} },
        { node_key: 'm', type: 'send_email', config: { to: 'a@b.c', subject: 'S', body: 'B' } },
    ];
    assert.deepEqual(computeNodeIssues(nodes, library), []);
});

/**
 * Delay-node regression: the schema declares `unit` as required WITH a
 * default of "minutes". The config panel rendered that default, so the field
 * looked filled in, but it never reached the model — the node stayed flagged
 * "Invalid" with a "This field is required." message under a visibly
 * pre-selected "Minutes", and only re-picking that same option fixed it.
 */
const delayLibrary = {
    triggers: [{ handle: 'manual', schema: [] }],
    logic: [
        {
            handle: 'delay',
            schema: [
                { handle: 'amount', label: 'Amount', type: 'number', required: true, default: 1 },
                {
                    handle: 'unit',
                    label: 'Unit',
                    type: 'select',
                    required: true,
                    default: 'minutes',
                    options: [
                        { value: 'minutes', label: 'Minutes' },
                        { value: 'hours', label: 'Hours' },
                    ],
                },
            ],
        },
    ],
    actions: [],
};

test('defaultConfigForSchema seeds every declared default into the model', () => {
    const schema = schemaFor({ type: 'delay' }, delayLibrary);
    assert.deepEqual(defaultConfigForSchema(schema), { amount: 1, unit: 'minutes' });
});

test('defaultConfigForSchema skips fields without a usable default', () => {
    assert.deepEqual(
        defaultConfigForSchema([
            { handle: 'a' },
            { handle: 'b', default: null },
            { handle: 'c', default: undefined },
            { handle: 'd', default: '' },
            { handle: 'e', default: 0 },
            { handle: 'f', default: false },
            { default: 'no-handle' },
        ]),
        { d: '', e: 0, f: false },
    );
    assert.deepEqual(defaultConfigForSchema(undefined), {});
});

test('a freshly created delay node is valid without any user interaction', () => {
    const schema = schemaFor({ type: 'delay' }, delayLibrary);
    const node = { node_key: 'delay_ab12', type: 'delay', config: defaultConfigForSchema(schema) };

    assert.deepEqual(missingRequiredHandles(node, delayLibrary), []);
    assert.deepEqual(computeNodeIssues([node], delayLibrary), []);
    // The default must be PERSISTED, not merely displayed.
    assert.equal(node.config.unit, 'minutes');
});

test('without seeding, the same delay node is flagged invalid (the old behaviour)', () => {
    const node = { node_key: 'delay_ab12', type: 'delay', config: {} };
    assert.deepEqual(missingRequiredHandles(node, delayLibrary), ['amount', 'unit']);
});
