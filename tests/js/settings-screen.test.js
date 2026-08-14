import { describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';

import Show from '../../resources/js/pages/Settings/Show.vue';

/**
 * The settings screen, after it stopped being a printout.
 *
 * It used to render `config/automations.php` as read-only rows and tell the
 * operator to go and edit a file on the server. The form that replaced it is
 * generated from the `groups` the server sends — the same definition the
 * validation and the boot-time config override read — so the tests here are
 * about the wiring between that definition and the request, not about any
 * particular setting.
 */

const groups = () => [
    {
        title: 'Queue',
        description: 'Where automation jobs are dispatched.',
        fields: [
            { key: 'queue', type: 'string', label: 'Queue name', description: '', nullable: false },
            { key: 'queue_connection', type: 'string', label: 'Connection', description: '', nullable: true },
        ],
    },
    {
        title: 'Runs',
        description: 'How much of each run is kept.',
        fields: [
            { key: 'runs.prune_after_days', type: 'integer', label: 'Retention', description: '', nullable: false, min: 1 },
            { key: 'runs.store_full_context', type: 'boolean', label: 'Store full context', description: '', nullable: false },
        ],
    },
    {
        title: 'Payload redaction',
        description: 'Keys replaced before storing.',
        fields: [
            { key: 'security.redact_keys', type: 'list', label: 'Redacted keys', description: '', nullable: false },
        ],
    },
];

const values = (overrides = {}) => ({
    queue: 'default',
    queue_connection: null,
    'runs.prune_after_days': 30,
    'runs.store_full_context': true,
    'security.redact_keys': ['password', 'token'],
    ...overrides,
});

function mountScreen(props = {}) {
    return mount(Show, {
        props: {
            title: 'Automation Settings',
            config_path: 'config/automations.php',
            groups: groups(),
            values: values(),
            updateUrl: '/cp/automations/api/settings',
            canEdit: true,
            integrations: { leadhub: true, webhook_manager: false },
            ...props,
        },
    });
}

describe('the settings screen', () => {
    it('draws one control per field the server defines', () => {
        const wrapper = mountScreen();

        for (const group of groups()) {
            for (const field of group.fields) {
                expect(wrapper.find(`[data-settings-field="${field.key}"]`).exists()).toBe(true);
            }
        }
    });

    it('sends every field, not only the one that changed', async () => {
        // The server's rules are `present`: a partial payload is refused rather
        // than written in part. That contract only holds while the form submits
        // the whole set.
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: { data: values() } });

        const wrapper = mountScreen();
        wrapper.vm.form['runs.prune_after_days'] = 45;
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-settings-save]').trigger('click');
        await flushPromises();

        const [, body] = patch.mock.calls[0];

        expect(Object.keys(body.settings).sort()).toEqual(
            groups().flatMap((g) => g.fields.map((f) => f.key)).sort(),
        );
        expect(body.settings['runs.prune_after_days']).toBe(45);

        patch.mockRestore();
    });

    it('sends a list as a list, one line per key', async () => {
        // The control is a textarea because that is how a set of key names is
        // read and pasted. The server keeps receiving an array, so nothing on
        // that side has to guess at a separator.
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: { data: values() } });

        const wrapper = mountScreen();
        wrapper.vm.form['security.redact_keys'] = 'password\n  iban  \n\ntoken';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-settings-save]').trigger('click');
        await flushPromises();

        expect(patch.mock.calls[0][1].settings['security.redact_keys'])
            .toEqual(['password', 'iban', 'token']);

        patch.mockRestore();
    });

    it('takes the answer rather than keeping what was typed', async () => {
        // The server normalises: an empty nullable field comes back null, a
        // number typed into a text box comes back a number, a value returned to
        // its default comes back as the default with the override deleted.
        // Keeping the submitted values would leave the screen agreeing with
        // itself and disagreeing with the installation.
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({
            data: { data: values({ 'runs.prune_after_days': 30, queue_connection: null }) },
        });

        const wrapper = mountScreen();
        wrapper.vm.form['runs.prune_after_days'] = '30';
        wrapper.vm.form.queue_connection = '';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-settings-save]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.form['runs.prune_after_days']).toBe(30);
        expect(wrapper.vm.form.queue_connection).toBe('');

        patch.mockRestore();
    });

    it('shows a rejected value at the field it belongs to', async () => {
        const patch = vi.spyOn(axios, 'patch').mockRejectedValue({
            response: { data: { errors: { 'settings.runs.prune_after_days': ['Must be at least 1.'] } } },
        });

        const wrapper = mountScreen();
        wrapper.vm.form['runs.prune_after_days'] = 0;
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-settings-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-settings-field-error="runs.prune_after_days"]').text())
            .toContain('Must be at least 1.');
        expect(wrapper.find('[data-settings-form-errors]').exists()).toBe(false);

        patch.mockRestore();
    });

    it('surfaces an error that belongs to no field on this page', async () => {
        // There should be none — every validated key has a field here. A rule
        // added on the server and not here would otherwise be rejected in
        // silence, which is the failure this addon has already shipped twice.
        const patch = vi.spyOn(axios, 'patch').mockRejectedValue({
            response: { data: { errors: { 'settings.something.new': ['Nope.'] } } },
        });

        const wrapper = mountScreen();
        wrapper.vm.form.queue = 'other';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-settings-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-settings-form-errors]').text()).toContain('Nope.');

        patch.mockRestore();
    });

    it('offers no save button to a reader', () => {
        const wrapper = mountScreen({ canEdit: false });

        expect(wrapper.find('[data-settings-save]').exists()).toBe(false);
        expect(wrapper.text()).toContain('manage automation settings');
    });
});
