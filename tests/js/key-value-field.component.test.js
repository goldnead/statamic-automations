import { expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import KeyValueField from '../../resources/js/components/builder/KeyValueField.vue';

/**
 * `tests/js/key-value-field.test.mjs` covers `useKeyValueRows` — the pure
 * coercion between the stored map and the editable rows. This covers the
 * component around it, which has state of its own and is reused by ConfigPanel
 * across node selections.
 */

it('follows a label prop that changes after mount', async () => {
    // `const keyLabel = props.keyLabel ?? __('Key')` was read once at setup.
    // ConfigPanel passes the labels conditionally (`if (field.key_label) …`),
    // so the same instance can be handed different ones without remounting —
    // and the placeholders kept describing whichever field got there first.
    const wrapper = mount(KeyValueField, {
        props: { modelValue: { a: '1' }, keyLabel: 'Branch handle', valueLabel: 'Label' },
    });

    expect(wrapper.find('[data-attr-placeholder="Branch handle"]').exists()).toBe(true);

    await wrapper.setProps({ keyLabel: 'Case value', valueLabel: 'Output' });

    expect(wrapper.find('[data-attr-placeholder="Case value"]').exists()).toBe(true);
    expect(wrapper.find('[data-attr-placeholder="Branch handle"]').exists()).toBe(false);
});

it('falls back to the generic label when the schema supplies an empty one', async () => {
    const wrapper = mount(KeyValueField, {
        props: { modelValue: {}, keyLabel: '', valueLabel: '' },
    });

    await wrapper.findAll('[data-attr-text="Add row"]')[0].trigger('click');

    expect(wrapper.find('[data-attr-placeholder="Key"]').exists()).toBe(true);
    expect(wrapper.find('[data-attr-placeholder="Value"]').exists()).toBe(true);
});
