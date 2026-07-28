import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import ConfigPanel from '../../resources/js/components/builder/ConfigPanel.vue';

/**
 * The config panel is the builder's single most stateful component and the one
 * place PHPUnit cannot reach: the controller hands over a node schema, and what
 * the editor does with it — which fields it renders, which it swallows, what it
 * carries over when another node is selected — happens entirely in the browser.
 */

/** A node type that owns conditions: `mode` is the all/any selector. */
const filterSchema = {
    handle: 'filter',
    label: 'Filter',
    schema: [
        { handle: 'conditions', label: 'Conditions', type: 'conditions' },
        {
            handle: 'mode',
            label: 'Match mode',
            type: 'select',
            options: [{ value: 'all', label: 'All' }, { value: 'any', label: 'Any' }],
        },
    ],
};

/** A node type that has a `mode` and no conditions at all. */
const groupSchema = {
    handle: 'add_user_to_group',
    label: 'Add user to group',
    schema: [
        { handle: 'group', label: 'Group', type: 'text' },
        {
            handle: 'mode',
            label: 'Mode',
            type: 'select',
            default: 'add',
            options: [{ value: 'add', label: 'Add to group' }, { value: 'remove', label: 'Remove from group' }],
        },
    ],
};

const keyValueSchema = {
    handle: 'send_webhook',
    label: 'Send webhook',
    schema: [{ handle: 'headers', label: 'Headers', type: 'key_value' }],
};

function mountPanel(node, schemas = [groupSchema, filterSchema, keyValueSchema]) {
    return mount(ConfigPanel, {
        props: {
            node,
            library: { triggers: [], logic: [], actions: schemas },
            apiBase: '/cp/automations/api',
        },
    });
}

const node = (type, extra = {}) => ({ node_key: type + '_1', type, label: null, config: {}, ...extra });

describe('which fields reach the form', () => {
    it('renders the mode field of a node that has no conditions', () => {
        // The filter used to be unconditional: `!['conditions', 'mode'].includes(handle)`.
        // ConditionBuilder — the component that renders `mode` instead — only
        // mounts when the schema declares `conditions`, so for the four node
        // types that declare a `mode` and no conditions the field was removed
        // and nothing put it back. `add_user_to_group` and `assign_user_role`
        // therefore had no reachable way to say "remove": the panel offered the
        // group and nothing else, and `defaultConfigForSchema` seeded `add`, so
        // the node validated and looked complete.
        const wrapper = mountPanel(node('add_user_to_group'));

        expect(wrapper.text()).toContain('Mode');
        expect(wrapper.find('[data-attr-label="Mode"]').exists()).toBe(true);
    });

    it('still hands mode to the condition builder where conditions exist', () => {
        // The other half of the same decision. For filter / branch / wait_until,
        // `mode` IS the all/any selector inside ConditionBuilder, and rendering
        // it a second time in the generic loop would give the node two controls
        // writing the same key.
        const wrapper = mountPanel(node('filter'));

        expect(wrapper.find('[data-attr-label="Match mode"]').exists()).toBe(false);
    });
});

describe('falling back when the backend sends an empty string', () => {
    it('uses the node type as the name placeholder when the schema label is empty', () => {
        // `schema?.label ?? node.type` only substitutes for null. A node class
        // whose `label()` returns '' produced an empty placeholder, while the
        // heading directly above it — which uses `||` — fell back correctly.
        const wrapper = mountPanel(
            node('add_user_to_group'),
            [{ ...groupSchema, label: '' }],
        );

        const placeholders = wrapper.findAll('[data-attr-placeholder]')
            .map((el) => el.attributes('data-attr-placeholder'));

        expect(placeholders).toContain('add_user_to_group');
    });
});

describe('state carried across node selections', () => {
    it('forgets which field opened the template picker', async () => {
        // `emailFieldHandle` is the handle `onEmailTemplateSelected` writes to.
        // The panel instance survives a node switch (Edit.vue mounts it with
        // v-if, not :key), so left pointing at the previous node's field, a
        // template picked afterwards landed on a handle the current node may
        // not even have.
        const emailSchema = {
            handle: 'send_email',
            label: 'Send email',
            schema: [{ handle: 'template', label: 'Template', type: 'text', preview: 'email' }],
        };

        const wrapper = mount(ConfigPanel, {
            props: {
                node: node('send_email'),
                library: { triggers: [], logic: [], actions: [emailSchema, groupSchema] },
                apiBase: '/cp/automations/api',
            },
        });

        const picker = wrapper.findAll('[data-attr-text]')
            .find((el) => el.attributes('data-attr-text') === 'Vorlage wählen');
        await picker.trigger('click');

        const modal = () => wrapper.findAll('[data-attr-title="E-Mail-Vorlage wählen"]')[0];

        expect(modal().attributes('data-attr-open')).toBe('true');

        await wrapper.setProps({ node: node('add_user_to_group') });

        expect(modal().attributes('data-attr-open')).toBe('false');
    });

    it('does not carry a key-value field\'s rows over to the next node', async () => {
        // The field loop was keyed by `field.handle` alone, so two nodes with a
        // `headers` field shared one KeyValueField instance — and that
        // component keeps its rows in local state, resyncing only when the
        // incoming value differs from what it last emitted. Two empty maps
        // serialise identically, so the resync was skipped and the previous
        // node's half-typed rows stayed on screen, ready to be committed onto
        // the new node by the next keystroke.
        const wrapper = mountPanel(node('send_webhook'));

        const addRow = wrapper.findAll('[data-attr-text]')
            .find((el) => el.attributes('data-attr-text') === 'Add row');
        await addRow.trigger('click');
        await addRow.trigger('click');

        const rowsBefore = wrapper.findAll('[data-attr-placeholder="Key"]').length;
        expect(rowsBefore).toBe(2);

        await wrapper.setProps({
            node: { node_key: 'send_webhook_2', type: 'send_webhook', label: null, config: {} },
        });

        expect(wrapper.findAll('[data-attr-placeholder="Key"]').length).toBe(0);
    });
});
