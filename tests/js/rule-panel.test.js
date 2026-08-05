import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import RulePanel from '../../resources/js/components/rules/RulePanel.vue';

/**
 * The rule, as one row a person can read and change.
 *
 * Four things are not negotiable:
 *
 *   - the row reads as a sentence, so somebody scanning the screen for "which
 *     mail goes out when the contact form is submitted" finds it,
 *   - a shape that is not a rule is SHOWN, with the reason on it and the way to
 *     the canvas, never hidden,
 *   - the immediate-sending switch says what it actually costs — the request
 *     waits for the whole run — and does not claim that errors surface in it,
 *     because WorkflowRunner writes a failed run and returns normally,
 *   - a save carries what was changed, not the whole row.
 */

const rule = (overrides = {}) => ({
    id: 1,
    handle: 'contact-reply',
    name: 'Contact reply',
    enabled: true,
    trigger: { handle: 'form_submitted', label: 'Form submitted' },
    mail: { label: 'Thanks for writing', reference: 'welcome' },
    recipient: 'hallo@example.com',
    template: 'welcome',
    dispatch_mode: 'async',
    editable: true,
    reasons: [],
    recent_runs: [],
    edit_url: '/cp/automations/automations/1/edit',
    template_options: [{ value: 'welcome', label: 'Welcome mail' }],
    ...overrides,
});

const mountPanel = (props = {}) => mount(RulePanel, { props: { rule: rule(), canEdit: true, ...props } });

describe('RulePanel', () => {
    it('reads as one sentence', () => {
        const text = mountPanel().text();

        expect(text).toContain('Form submitted');
        expect(text).toContain('Thanks for writing');
        expect(text).toContain('hallo@example.com');
    });

    it('shows a shape it cannot edit, names why, and offers the canvas', () => {
        const wrapper = mountPanel({
            rule: rule({
                editable: false,
                reasons: ['The automation has 1 step(s) besides the trigger and the mail (delay), which a single rule row cannot show.'],
            }),
        });

        expect(wrapper.find('[data-rule-locked]').exists()).toBe(true);
        expect(wrapper.text()).toContain('delay');
        // Still readable: the sentence is what the reader came for.
        expect(wrapper.text()).toContain('hallo@example.com');
        expect(wrapper.find('[data-rule-form]').exists()).toBe(false);
    });

    it('says what immediate sending costs without claiming errors surface', () => {
        const help = mountPanel().find('[data-rule-dispatch-help]');

        expect(help.exists()).toBe(true);
        expect(help.text()).toMatch(/waits/i);
        // The spec said errors reach the caller. They do not — WorkflowRunner
        // writes STATUS_FAILED on the run and returns. Promising otherwise here
        // would send somebody debugging a request that never failed.
        expect(help.text()).not.toMatch(/error|exception|fails/i);
    });

    it('sends only what was changed', async () => {
        const wrapper = mountPanel();

        await wrapper.findComponent('[data-rule-recipient]').vm.$emit('update:model-value', 'team@example.com');
        await wrapper.find('[data-rule-save]').trigger('click');

        expect(wrapper.emitted('save')).toHaveLength(1);
        expect(wrapper.emitted('save')[0][0]).toEqual({ recipient: 'team@example.com' });
    });

    it('offers no save while nothing has changed', () => {
        const wrapper = mountPanel();

        expect(wrapper.find('[data-rule-save]').attributes('data-attr-disabled')).toBe('true');
    });

    it('lets somebody without the edit permission read it but not change it', () => {
        const wrapper = mountPanel({ canEdit: false });

        expect(wrapper.find('[data-rule-readonly]').exists()).toBe(true);
        expect(wrapper.find('[data-rule-form]').exists()).toBe(false);
        expect(wrapper.text()).toContain('hallo@example.com');
    });
});
