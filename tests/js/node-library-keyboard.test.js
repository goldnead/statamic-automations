import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import { NODE_KINDS, nodeIcon } from '../../resources/js/support/nodeKinds.js';
import NodeLibrary from '../../resources/js/components/builder/NodeLibrary.vue';

/**
 * Adding a node is the primary action of this addon, and for a long time it was
 * mouse-only: every palette entry rendered as an `<li>` carrying an `onClick`,
 * with no role, no tabindex and no key handler. A keyboard or screen-reader user
 * could reach the search box and the tabs — both native components — and then
 * had no way to add anything.
 *
 * These tests pin the native-button shape so the render function cannot quietly
 * regress to a clickable div.
 */

const library = {
    triggers: [{ handle: 'form_submitted', label: 'Form submitted', description: 'When a form is submitted' }],
    logic: [{ handle: 'filter', label: 'Filter', description: 'Continue only if conditions match' }],
    actions: [{ handle: 'send_email', label: 'Send email', description: 'Send a templated email' }],
};

function mountLibrary(props = {}) {
    return mount(NodeLibrary, { props: { library, kinds: NODE_KINDS, nodeIcon, ...props } });
}

describe('node library palette', () => {
    it('renders every entry as a real button', () => {
        const wrapper = mountLibrary();
        const buttons = wrapper.findAll('li > button[type="button"]');

        expect(buttons.length).toBeGreaterThan(0);
        expect(buttons[0].text()).toContain('Form submitted');
    });

    it('never leaves a click handler on a non-interactive list item', () => {
        const wrapper = mountLibrary();

        for (const li of wrapper.findAll('li')) {
            // A list item is allowed to exist; it is not allowed to be the thing
            // you click. If it carries no button, it must carry no handler.
            if (li.find('button').exists()) {
                continue;
            }

            expect(li.attributes('onclick')).toBeUndefined();
        }
    });

    it('emits add when a palette button is activated', async () => {
        const wrapper = mountLibrary();

        await wrapper.find('li > button[type="button"]').trigger('click');

        expect(wrapper.emitted('add')?.[0]).toEqual(['form_submitted']);
    });

    it('activates on Enter, because it is a button', async () => {
        const wrapper = mountLibrary();
        const button = wrapper.find('li > button[type="button"]');

        // jsdom does not synthesise the click a real browser fires for Enter on
        // a <button>, so assert the property that earns that behaviour instead.
        expect(button.element.tagName).toBe('BUTTON');
        expect(button.attributes('type')).toBe('button');
        expect(button.attributes('disabled')).toBeUndefined();
    });
});
