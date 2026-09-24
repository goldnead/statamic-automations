import { beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async () => ({ data: {} })),
        post: vi.fn(async () => ({ data: {} })),
        patch: vi.fn(async () => ({ data: { data: {} } })),
        delete: vi.fn(async () => ({ data: {} })),
    },
}));

import axios from 'axios';
import Edit from '../../resources/js/pages/Connections/Edit.vue';
import OperationStack from '../../resources/js/components/connections/OperationStack.vue';
import DeleteConnectionModal from '../../resources/js/components/connections/DeleteConnectionModal.vue';

/**
 * The connections screens. What is worth pinning here is what a screenshot
 * cannot show: that a stored credential is never put back into a field, that
 * an untouched credential goes out empty (the API's "unchanged"), and that a
 * refusal from the server lands on the field it is about.
 */

const connection = {
    id: 7,
    handle: 'crm',
    name: 'CRM',
    base_url: 'https://api.example.test',
    auth_type: 'bearer',
    auth_config: { token: '••••••••' },
    auth_configured: true,
    default_headers: {},
    timeout: 15,
    test_path: '/me',
    operations: [],
    used_by: [],
    show_url: '/cp/automations/api/connections/7',
    test_url: '/cp/automations/api/connections/7/test',
    edit_url: '/cp/automations/connections/7/edit',
};

function mountEdit(overrides = {}) {
    return mount(Edit, {
        props: {
            title: 'CRM',
            connection,
            isNew: false,
            indexUrl: '/cp/automations/connections',
            storeUrl: '/cp/automations/api/connections',
            operationsUrl: '/cp/automations/api/connections/7/operations',
            authTypes: { none: 'No authentication', header: 'Header', bearer: 'Bearer token', basic: 'Basic auth' },
            authFields: { none: [], header: ['name', 'value'], bearer: ['token'], basic: ['username', 'password'] },
            methods: ['GET', 'POST'],
            inputTypes: ['text', 'select'],
            ...overrides,
        },
    });
}

function rejected(errors) {
    const error = new Error('Request failed with status code 422');
    error.response = { status: 422, data: { message: 'The given data was invalid.', errors } };
    return error;
}

beforeEach(() => {
    vi.clearAllMocks();
});

it('never puts a stored credential back into its field, and marks it as stored', () => {
    const wrapper = mountEdit();
    const token = wrapper.find('[data-auth-field="token"]');

    expect(token.attributes('data-attr-model-value')).toBe(undefined);
    expect(token.attributes('data-attr-placeholder')).toBe('••••••••');
    expect(token.attributes('data-attr-type')).toBe('password');
});

it('sends an untouched credential empty, which the API reads as unchanged', async () => {
    axios.patch.mockResolvedValueOnce({ data: { data: connection } });
    const wrapper = mountEdit();

    await wrapper.find('[data-connection-save]').trigger('click');
    await flushPromises();

    expect(axios.patch).toHaveBeenCalledWith(connection.show_url, expect.objectContaining({
        auth_type: 'bearer',
        auth_config: { token: '' },
    }));
});

it('shows a refused base URL at its field and flags the tab', async () => {
    const refusal = "The host '127.0.0.1' points to a private or reserved address (127.0.0.1).";
    axios.patch.mockRejectedValueOnce(rejected({ base_url: [refusal], name: ['This field is required.'] }));
    const wrapper = mountEdit();

    await wrapper.find('[data-connection-save]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-stub="Field"][data-attr-id="base_url"]').attributes('data-attr-error')).toBe(refusal);
    expect(wrapper.find('[data-stub="Field"][data-attr-id="name"]').attributes('data-attr-error')).toBe('This field is required.');
    // The error badge sits on the Connection tab, none on Access.
    const triggers = wrapper.findAll('[data-stub="TabTrigger"]');
    expect(triggers[0].find('[data-attr-text="!"]').exists()).toBe(true);
    expect(triggers[1].find('[data-attr-text="!"]').exists()).toBe(false);
});

it('shows what the test answered: status and time, or the reason it could not connect', async () => {
    axios.post.mockResolvedValueOnce({ data: { ok: true, status: 200, duration_ms: 84 } });
    const wrapper = mountEdit();

    await wrapper.find('[data-connection-test]').trigger('click');
    await flushPromises();

    let result = wrapper.find('[data-connection-test-result]');
    expect(result.attributes('data-attr-variant')).toBe('success');
    expect(result.attributes('data-attr-text')).toBe('HTTP 200 · 84 ms');

    axios.post.mockResolvedValueOnce({
        data: { ok: false, status: null, duration_ms: 0, error: "The host '10.0.0.5' points to a private or reserved address (10.0.0.5)." },
    });
    await wrapper.find('[data-connection-test]').trigger('click');
    await flushPromises();

    result = wrapper.find('[data-connection-test-result]');
    expect(result.attributes('data-attr-variant')).toBe('error');
    expect(result.attributes('data-attr-heading')).toBe('Could not connect');
    expect(result.attributes('data-attr-text')).toContain('private or reserved address');
});

it('names the automations that still use a connection before it is deleted', async () => {
    axios.get.mockResolvedValueOnce({
        data: { data: { ...connection, used_by: [{ id: 3, name: 'Lead to CRM', handle: 'lead_to_crm' }] } },
    });
    const { used_by: _omitted, ...listed } = connection;

    const wrapper = mount(DeleteConnectionModal, { props: { connection: listed } });
    await flushPromises();

    expect(axios.get).toHaveBeenCalledWith(connection.show_url);
    expect(wrapper.find('[data-connection-used-by]').text()).toContain('Lead to CRM');
});

it('saves an operation with its inputs, and puts a refused input back on its row', async () => {
    const wrapper = mount(OperationStack, {
        props: {
            open: true,
            operation: {
                id: 4,
                handle: 'create_contact',
                name: 'Create contact',
                method: 'POST',
                path: '/contacts',
                query: {},
                body: { email: '{{ input.email }}' },
                inputs: [
                    { handle: 'email', label: 'Email', type: 'text', required: true },
                    { handle: 'List', label: 'List', type: 'select', options: [{ value: 'a', label: 'A' }] },
                ],
                response_map: { id: 'data.id' },
                fail_on_error_status: true,
            },
            operationsUrl: '/cp/automations/api/connections/7/operations',
            methods: ['GET', 'POST'],
            inputTypes: ['text', 'select'],
        },
    });

    axios.patch.mockRejectedValueOnce(rejected({ 'inputs.1.handle': ['The inputs.1.handle field format is invalid.'] }));
    await wrapper.find('[data-operation-save]').trigger('click');
    await flushPromises();

    const [url, payload] = axios.patch.mock.calls[0];
    expect(url).toBe('/cp/automations/api/connections/7/operations/4');
    expect(payload.inputs[1]).toMatchObject({ handle: 'List', type: 'select', options: [{ value: 'a', label: 'A' }] });
    expect(payload.inputs[0]).not.toHaveProperty('options');

    const second = wrapper.find('[data-operation-input="1"]');
    expect(second.find('[data-stub="Field"][data-attr-error]').attributes('data-attr-error'))
        .toBe('The inputs.1.handle field format is invalid.');
    expect(wrapper.find('[data-operation-input="0"] [data-attr-error]').exists()).toBe(false);
});
