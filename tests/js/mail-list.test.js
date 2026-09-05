import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';

import MailListPanel from '../../resources/js/components/mails/MailListPanel.vue';

/**
 * The mail list, driven the way a user drives it.
 *
 * The three behaviours that are not negotiable, per the brief:
 *
 *   - a branched automation is SHOWN and not editable,
 *   - a mail only some readers get is marked as such,
 *   - a reorder sends the whole order, in the order the user asked for.
 *
 * The fourth is the one that turns a refusal into something actionable: the
 * reason has to name which of the seven conditions of Sequence\LinearityRule is
 * broken, not merely that the flow "is not linear".
 */

const mail = (node_key, overrides = {}) => ({
    position: 0,
    node_key,
    type: 'send_email',
    label: node_key.toUpperCase(),
    reference: null,
    disabled: false,
    delay: { seconds: 0, sources: [] },
    conditional: false,
    condition: null,
    also_runs: [],
    ...overrides,
});

/** t → m1, and m2 hanging off a branch's `true` output. */
const branchedList = () => ({
    mails: [
        mail('m1', { delay: { seconds: 2 * 86400, sources: ['d1'] } }),
        mail('m2', { conditional: true, condition: 'Branch → true' }),
    ],
    editable: false,
    reasons: ["Node 'br' is a branch node, which forks the flow by nature."],
    trigger: 't',
    tail: [],
});

const linearList = () => ({
    mails: [mail('m1'), mail('m2'), mail('m3')],
    editable: true,
    reasons: [],
    trigger: 't',
    tail: [],
});

/**
 * The shared stub in setup.js renders only a component's default slot, and the
 * add form's buttons live in the Modal's `footer` slot — i.e. exactly the part
 * a test of that form has to reach. This one renders every slot it is given.
 */
const ModalStub = defineComponent({
    name: 'Modal',
    setup: (_props, { slots }) => () => h('div', Object.values(slots).map((slot) => slot())),
});

function mountPanel(list, props = {}) {
    return mount(MailListPanel, {
        props: { list, canEdit: true, graphDirty: false, types: [{ handle: 'send_email', label: 'Send Email' }], ...props },
        global: { stubs: { Modal: ModalStub } },
    });
}

const buttons = (wrapper, icon) => wrapper.findAll(`[data-attr-icon="${icon}"]`);
const cell = (wrapper, nodeKey, column) => wrapper
    .findAll('[data-listing-row]')
    .find((row) => row.attributes('data-listing-row') === nodeKey)
    .find(`[data-column="${column}"]`);

describe('MailListPanel', () => {
    it('shows a branched automation but refuses to rearrange it', () => {
        const wrapper = mountPanel(branchedList());

        // Shown: both mails are on screen, branch or no branch. The list is a
        // list of the e-mails, not a picture of the automation.
        expect(wrapper.findAll('[data-mail-row]')).toHaveLength(2);
        expect(wrapper.text()).toContain('M1');
        expect(wrapper.text()).toContain('M2');

        // Not editable: no move, no delete, no add. The delete is the action
        // behind `action-url`, so withholding that endpoint is what withholds
        // both the checkbox column and the delete.
        expect(buttons(wrapper, 'arrow-up')).toHaveLength(0);
        expect(buttons(wrapper, 'arrow-down')).toHaveLength(0);
        expect(wrapper.find('[data-stub="Listing"]').attributes('data-attr-action-url')).toBe('');
        expect(wrapper.find('[data-mail-add]').exists()).toBe(false);
    });

    it('is a table with a heading over every column', () => {
        // The complaint this answers: a stack of cards has no column headings,
        // nothing to sort by, and two mails fill the screen.
        const wrapper = mountPanel(linearList());

        const heads = wrapper.findAll('[data-column-head]');

        expect(heads.map((head) => head.attributes('data-column-head')))
            .toEqual(['position', 'label', 'reference', 'delay', 'condition', 'also_runs']);
        expect(heads.every((head) => head.attributes('data-sortable') === 'true')).toBe(true);
    });

    it('offers the checkbox column exactly when a selection could do something', () => {
        // `action-url` is what turns Statamic's checkbox column on. A list that
        // may not be changed gets none, rather than checkboxes that select and
        // then offer nothing.
        const url = '/cp/automations/api/automations/3/mail-list/actions';

        expect(mountPanel(linearList(), { actionUrl: url })
            .find('[data-stub="Listing"]').attributes('data-attr-action-url')).toBe(url);

        expect(mountPanel(linearList(), { actionUrl: url, canEdit: false })
            .find('[data-stub="Listing"]').attributes('data-attr-action-url')).toBe('');

        expect(mountPanel(branchedList(), { actionUrl: url })
            .find('[data-stub="Listing"]').attributes('data-attr-action-url')).toBe('');
    });

    it('names the condition that is broken, not merely that the flow is not linear', () => {
        const wrapper = mountPanel(branchedList());
        const notice = wrapper.find('[data-mail-list-locked]');

        expect(notice.exists()).toBe(true);
        // The whole point: an editor is told WHICH of the seven, in words they
        // can act on, plus the server's own sentence naming the node.
        expect(notice.text()).toContain('Condition 5 of 7');
        expect(notice.text()).toContain('no Branch, Switch, Loop or Parallel step');
        expect(notice.text()).toContain("Node 'br' is a branch node");
    });

    it('opens a mail by its name', () => {
        // Reading and moving are not the same act. Before this the list could
        // reorder, assign and delete a mail but never show what was in it —
        // the only way to read one was to find its node on the canvas.
        const wrapper = mountPanel(linearList());

        wrapper.find('[data-mail-open="m2"]').trigger('click');

        expect(wrapper.emitted('open')?.[0]?.[0]?.node_key).toBe('m2');
    });

    it('opens a mail even in a list that cannot be rearranged', () => {
        // Reading is always on. A branched automation refuses reordering, and
        // that has nothing to do with whether its mails can be read.
        const wrapper = mountPanel(branchedList());

        wrapper.find('[data-mail-open="m2"]').trigger('click');

        expect(wrapper.emitted('open')?.[0]?.[0]?.node_key).toBe('m2');
    });

    it('marks a mail that only some readers get, and names the fork', () => {
        const wrapper = mountPanel(branchedList());

        expect(wrapper.find('[data-mail-conditional="m2"]').exists()).toBe(true);
        expect(wrapper.find('[data-mail-conditional="m1"]').exists()).toBe(false);
        // The fork itself is named in the cell, next to the badge: the badge
        // says "not everybody", the text says which fork decides it.
        expect(cell(wrapper, 'm2', 'condition').text()).toContain('Branch → true');
        expect(cell(wrapper, 'm1', 'condition').text()).toBe('—');
    });

    it('counts the gap from the mail before it, never from the start', () => {
        const wrapper = mountPanel(branchedList());

        // The first row's anchor is the trigger; there is nothing else in front
        // of it. Everything below is relative to the row above.
        expect(wrapper.find('[data-mail-delay="m1"]').text()).toBe('Sent 2 days after the trigger');
        expect(wrapper.find('[data-mail-delay="m2"]').text()).toBe('Sent as soon as the previous mail is out');
    });

    it('sends the whole order, in the order the user asked for', async () => {
        const wrapper = mountPanel(linearList());

        await buttons(wrapper, 'arrow-down')[0].trigger('click');

        expect(wrapper.emitted('reorder')).toEqual([[['m2', 'm1', 'm3']]]);

        // Row one has no "up", so the second of the two is row three's.
        await buttons(wrapper, 'arrow-up')[1].trigger('click');

        expect(wrapper.emitted('reorder')[1]).toEqual([['m1', 'm3', 'm2']]);
    });

    it('cannot walk a mail off either end of the list', () => {
        // A dropdown item has no disabled state, so the move that cannot happen
        // is not offered at all — which reads better than a greyed-out entry
        // and cannot be clicked by a keyboard either.
        const wrapper = mountPanel(linearList());
        const rows = wrapper.findAll('[data-listing-row]');

        expect(rows[0].findAll('[data-attr-icon="arrow-up"]')).toHaveLength(0);
        expect(rows[0].findAll('[data-attr-icon="arrow-down"]')).toHaveLength(1);
        expect(rows[2].findAll('[data-attr-icon="arrow-up"]')).toHaveLength(1);
        expect(rows[2].findAll('[data-attr-icon="arrow-down"]')).toHaveLength(0);
    });

    it('deletes through the action endpoint, not through a second button', async () => {
        // One delete path. The row menu used to carry a trash button that asked
        // the page to confirm; the bulk toolbar would have been a second one
        // with its own confirmation, and two paths to the same destructive act
        // is one refactor away from them disagreeing.
        const wrapper = mountPanel(linearList(), { actionUrl: '/x/actions' });

        expect(buttons(wrapper, 'trash')).toHaveLength(0);
        expect(wrapper.emitted('request-remove')).toBeUndefined();

        // And the table tells the page to re-read when core has run one.
        wrapper.findComponent({ name: 'Listing' }).vm.$emit('refreshing');

        expect(wrapper.emitted('refresh')).toHaveLength(1);
    });

    it('opens the mail from the row menu as well as from its name', async () => {
        const wrapper = mountPanel(linearList());
        const items = wrapper.findAll('[data-listing-row]')[1]
            .findAll('[data-row-actions] [data-stub="DropdownItem"]');

        expect(items.map((item) => item.attributes('data-attr-text')))
            .toEqual(['Open this mail', 'Move up', 'Move down']);

        await items[0].trigger('click');

        expect(wrapper.emitted('open')?.[0]?.[0]?.node_key).toBe('m2');
    });

    it('locks the list while the canvas has unsaved changes, and says so', () => {
        const wrapper = mountPanel(linearList(), { graphDirty: true });

        expect(wrapper.find('[data-mail-list-dirty]').exists()).toBe(true);
        expect(buttons(wrapper, 'arrow-down')).toHaveLength(0);
        // A dirty canvas is not a broken flow — the linearity notice must not
        // appear and blame the automation for something the editor did.
        expect(wrapper.find('[data-mail-list-locked]').exists()).toBe(false);
    });

    it('lets a reader without the edit permission read it', () => {
        const wrapper = mountPanel(linearList(), { canEdit: false });

        expect(wrapper.findAll('[data-mail-row]')).toHaveLength(3);
        expect(wrapper.find('[data-mail-list-readonly]').exists()).toBe(true);
        expect(buttons(wrapper, 'arrow-down')).toHaveLength(0);
        // Reading is always on, so the mail can still be opened.
        expect(wrapper.findAll('[data-attr-text="Open this mail"]')).toHaveLength(3);
    });

    it('offers to add the first mail to an automation that sends none', async () => {
        const wrapper = mountPanel({ ...linearList(), mails: [] });

        expect(wrapper.text()).toContain('This automation sends no mail yet.');

        await wrapper.find('[data-mail-add]').trigger('click');
        await wrapper.find('[data-mail-add-submit]').trigger('click');

        // One registered mail type, so it is preselected — an add form whose
        // only choice is already made should not demand it be made again.
        expect(wrapper.emitted('insert')).toEqual([[{
            type: 'send_email',
            label: null,
            after: null,
            delay: { amount: 0, unit: 'days' },
        }]]);
    });
});
