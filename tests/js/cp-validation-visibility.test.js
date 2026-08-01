import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';

// `vi.mock` is hoisted above every top-level statement, so the factory cannot
// close over a const declared here — it has to build the mock itself.
vi.mock('axios', () => ({
    default: {
        get: vi.fn(async () => ({ data: {} })),
        post: vi.fn(async () => ({ data: { data: { id: 1, handle: 'flow' } } })),
        patch: vi.fn(async () => ({ data: { data: {} } })),
        delete: vi.fn(async () => ({ data: {} })),
    },
}));

import axiosMock from 'axios';

// Vue Flow (inside the canvas) constructs a ResizeObserver on mount. jsdom has
// none, and the resulting unhandled rejection lands mid-suite: the mount after
// it comes back without an instance, so a test that looked like a page defect
// was a missing browser API. A no-op is enough — nothing here measures.
globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

import Edit from '../../resources/js/pages/Automations/Edit.vue';
import Index from '../../resources/js/pages/Automations/Index.vue';

/**
 * Does a rejected request reach the screen, and does it still say what the
 * server said?
 *
 * This addon does not go through Inertia — every call is axios, so there is no
 * error bag handed to a page and nothing is surfaced that a `catch` does not
 * dig out by hand. Several call sites bound the error and then threw a
 * hardcoded string at the user instead, which reads to the user as if the
 * server had said nothing at all.
 *
 * The structural guard (tests/Feature/CpValidationVisibilityTest.php) can see
 * that a catch block exists and that it does not discard its binding. It
 * cannot see whether the message ends up in the DOM — that is what this file
 * is for, and it is the layer that caught the equivalent defect in marketing
 * v1.5.3, where a key was declared as handled at its field while the field
 * only existed when creating.
 */

const stub = (name, props, emits) => defineComponent({ name, props, emits, setup: () => () => h('div') });

const CanvasStub = stub(
    'CanvasStub',
    ['nodes', 'edges', 'selectedKey', 'validation', 'library', 'pendingTarget'],
    ['select', 'remove-node', 'duplicate-node'],
);
const ConfigPanelStub = stub('ConfigPanelStub', ['node', 'library'], ['update:label', 'update:config']);

const HeaderStub = defineComponent({
    name: 'HeaderStub',
    setup: (_props, { slots }) => () => h('div', Object.values(slots).map((slot) => slot())),
});

const library = {
    triggers: [{ handle: 'manual', label: 'Manual', schema: [] }],
    logic: [],
    actions: [{ handle: 'send_email', label: 'Send email', schema: [] }],
};

const node = (node_key, type) => ({
    node_key, type, label: type, position_x: 0, position_y: 0, config: {}, disabled: false,
});

/** A rejected axios call, in the shape Laravel actually sends. */
function rejection(status, data) {
    return Object.assign(new Error(`Request failed with status code ${status}`), {
        response: { status, data },
    });
}

function mountEditor() {
    return mount(Edit, {
        props: {
            mode: 'edit',
            title: 'Flow',
            apiBase: '/cp/automations/api',
            indexUrl: '/cp/automations',
            library,
            automation: {
                id: 3,
                handle: 'flow',
                name: 'Flow',
                description: null,
                enabled: false,
                nodes: [node('t', 'manual'), node('a', 'send_email')],
                edges: [{ from_node_key: 't', from_output: 'default', to_node_key: 'a', to_input: 'default' }],
            },
        },
        global: {
            stubs: {
                Canvas: CanvasStub,
                ConfigPanel: ConfigPanelStub,
                Header: HeaderStub,
                NodeLibrary: true,
                RunLogPanel: true,
            },
        },
    });
}

function mountIndex() {
    return mount(Index, {
        props: {
            title: 'Automations',
            rows: [{ id: 7, name: 'Nightly', enabled: false, handle: 'nightly' }],
            columns: [{ field: 'name', label: 'Name' }],
            createUrl: '/cp/automations/automations/create',
            templatesUrl: '/cp/automations/templates',
            apiBase: '/cp/automations/api',
            canCreate: true,
        },
        global: { stubs: { Header: HeaderStub, Listing: true } },
    });
}

/** Everything the page is currently showing as an error, as plain text. */
function shownErrors(wrapper) {
    return [
        ...wrapper.findAll('[data-automations-form-errors]').map((el) => el.text()),
        ...wrapper.findAll('[data-automations-field-error]').map((el) => el.text()),
    ].join('\n');
}

const realConfirm = window.confirm;

beforeEach(() => {
    vi.clearAllMocks();
    axiosMock.get.mockImplementation(async () => ({ data: {} }));
    axiosMock.post.mockImplementation(async () => ({ data: { data: { id: 1, handle: 'flow' } } }));
    axiosMock.patch.mockImplementation(async () => ({ data: { data: {} } }));
    axiosMock.delete.mockImplementation(async () => ({ data: {} }));
});

afterEach(() => {
    // Deliberately not `vi.restoreAllMocks()`: these mocks come from a
    // `vi.mock` factory, and restoring them mid-file leaves the module in a
    // state where a later mount comes back without an instance. `beforeEach`
    // re-arms every implementation, which is what isolation actually needs.
    window.confirm = realConfirm;
});

describe('Automations/Edit — a rejected save', () => {
    it('shows the message at the name, not only in a toast that disappears', async () => {
        axiosMock.patch.mockRejectedValueOnce(rejection(422, {
            message: 'The given data was invalid.',
            errors: { name: ['The name has already been taken.'] },
        }));

        const wrapper = mountEditor();
        await wrapper.find('[data-attr-text="Save"]').trigger('click');
        await flushPromises();

        const atName = wrapper.find('[data-automations-field-error="name"]');
        expect(atName.exists(), 'the name error is nowhere near the name input').toBe(true);
        expect(atName.text()).toContain('The name has already been taken.');
    });

    it('shows keys that belong to no control instead of dropping all but the first', async () => {
        // The canvas generates `nodes.*` and `edges.*`; a rejection there names
        // an array index the user cannot see, so it has to be reported in full
        // rather than reduced to one toast line.
        axiosMock.patch.mockRejectedValueOnce(rejection(422, {
            message: 'The given data was invalid.',
            errors: {
                description: ['The description is too long.'],
                'nodes.0.type': ['The selected node type is invalid.'],
            },
        }));

        const wrapper = mountEditor();
        await wrapper.find('[data-attr-text="Save"]').trigger('click');
        await flushPromises();

        const shown = shownErrors(wrapper);
        expect(shown).toContain('The description is too long.');
        expect(shown).toContain('The selected node type is invalid.');
    });

    it('does not leave the rejection on screen once the value is being fixed', async () => {
        axiosMock.patch.mockRejectedValueOnce(rejection(422, {
            errors: { name: ['The name has already been taken.'] },
        }));

        const wrapper = mountEditor();
        await wrapper.find('[data-attr-text="Save"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-automations-field-error="name"]').exists()).toBe(true);

        await wrapper.find('input[type="text"]').setValue('Another name');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-automations-field-error="name"]').exists()).toBe(false);
    });
});

describe('Automations/Index — a refused row action', () => {
    it('says what the server said about a refused delete, not "Delete failed."', async () => {
        // `catch (e)` bound the error and then ignored it. The server's actual
        // sentence — the permission it wanted — never reached anyone.
        axiosMock.delete.mockRejectedValueOnce(rejection(403, {
            message: "Permission 'delete automations' is required.",
        }));

        // Deletion is confirmed in the CP's own ConfirmationModal now, so the
        // flow is arm-then-confirm. window.confirm is not stubbed on purpose:
        // if it ever comes back, jsdom throws "not implemented" here.
        const wrapper = mountIndex();
        wrapper.vm.$.setupState.confirmDestroy({ id: 7, name: 'Nightly' });
        await wrapper.vm.$.setupState.destroy();
        await flushPromises();

        expect(shownErrors(wrapper)).toContain("Permission 'delete automations' is required.");
    });

    it('asks in a CP modal rather than a browser dialog', async () => {
        const wrapper = mountIndex();

        const modal = () => wrapper.find('[data-stub="ConfirmationModal"]');

        expect(modal().exists()).toBe(true);
        expect(modal().attributes('data-attr-open')).toBe('false');

        wrapper.vm.$.setupState.confirmDestroy({ id: 7, name: 'Nightly' });
        await wrapper.vm.$nextTick();

        expect(modal().attributes('data-attr-open')).toBe('true');

        // The prompt text is built from the row, so it is what the modal shows.
        expect(wrapper.vm.$.setupState.deletePrompt).toContain('Nightly');
    });

    it('says what the server said about a refused duplicate', async () => {
        axiosMock.post.mockRejectedValueOnce(rejection(403, {
            message: "Permission 'create automations' is required.",
        }));

        const wrapper = mountIndex();
        await wrapper.vm.$.setupState.duplicate({ id: 7, name: 'Nightly' });
        await flushPromises();

        expect(shownErrors(wrapper)).toContain("Permission 'create automations' is required.");
    });

    it('shows the per-node reasons a refused enable comes back with', async () => {
        // The API answers a blocked enable with HTTP 422 and an `issues[]`
        // array. axios rejects a 422, so the page's `data.ok === false` branch
        // could never run and every one of those reasons was dropped.
        axiosMock.post.mockRejectedValueOnce(rejection(422, {
            ok: false,
            message: 'Could not enable.',
            issues: [{ level: 'error', message: 'Trigger node has no outgoing edge.', node_key: 't' }],
        }));

        const wrapper = mountIndex();
        await wrapper.vm.$.setupState.toggleEnabled({ id: 7, name: 'Nightly', enabled: false });
        await flushPromises();

        expect(shownErrors(wrapper)).toContain('Trigger node has no outgoing edge.');
    });
});

describe('Automations/Edit — a refused enable', () => {
    it('surfaces the issues the 422 carried instead of only "Toggle failed."', async () => {
        axiosMock.post.mockRejectedValueOnce(rejection(422, {
            ok: false,
            message: 'Could not enable.',
            issues: [{ level: 'error', message: 'Send email is missing a recipient.', node_key: 'a' }],
        }));

        const wrapper = mountEditor();
        await wrapper.vm.$.setupState.toggleEnabled();
        await flushPromises();

        expect(wrapper.text()).toContain('Send email is missing a recipient.');
    });
});
