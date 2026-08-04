import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';

import { builtInLibrary } from './fixtures/built-in-library.mjs';

vi.mock('axios', () => ({
    default: {
        patch: vi.fn(async () => ({ data: { data: {} } })),
        post: vi.fn(async () => ({ data: { data: { id: 1, handle: 'flow' } } })),
        get: vi.fn(async () => ({ data: {} })),
        delete: vi.fn(async () => ({ data: {} })),
    },
}));

import axios from 'axios';

import Edit from '../../resources/js/pages/Automations/Edit.vue';

/**
 * The builder page, driven the way a user drives it: the canvas reports a
 * deletion, the config panel reports typing, the header buttons undo and save.
 *
 * Canvas and ConfigPanel are stubbed — the first mounts Vue Flow, the second
 * has its own suite — but they are stubbed as themselves, so the page is wired
 * to them here exactly as it is in the Control Panel: what the stub receives
 * is what the canvas would paint, and what the stub emits is what the real
 * component emits.
 */

const stub = (name, props, emits) => defineComponent({ name, props, emits, setup: () => () => h('div') });

const CanvasStub = stub(
    'CanvasStub',
    ['nodes', 'edges', 'selectedKey', 'validation', 'library', 'pendingTarget'],
    ['select', 'remove-node', 'duplicate-node'],
);
const ConfigPanelStub = stub('ConfigPanelStub', ['node', 'library'], ['update:label', 'update:config']);

/**
 * `Header` is a Control Panel component, and the shared stub in setup.js only
 * renders the default slot — the undo/redo/save buttons live in its `actions`
 * slot, i.e. exactly the part a test of the header's buttons needs. This stub
 * renders every slot it is given.
 */
const HeaderStub = defineComponent({
    name: 'HeaderStub',
    setup: (_props, { slots }) => () => h('div', Object.values(slots).map((slot) => slot())),
});

// The server's payload, carrying each node's declared output handles. The
// page registers them (setNodeOutputSpecs) at the top of setup — without
// that, a branch on this canvas has one `default` output and duplicating it
// produces the edge FlowValidator rejects.
const library = builtInLibrary();

const node = (node_key, type) => ({
    node_key,
    type,
    label: type,
    position_x: 0,
    position_y: 0,
    config: {},
    disabled: false,
});

const edge = (from, output, to) => ({
    from_node_key: from,
    from_output: output,
    to_node_key: to,
    to_input: 'default',
});

/** t → a → b, the shape every linear flow has. */
const linearGraph = () => ({
    nodes: [node('t', 'manual'), node('a', 'send_email'), node('b', 'send_email')],
    edges: [edge('t', 'default', 'a'), edge('a', 'default', 'b')],
});

/** t → br, with br's `true` path wired to a step. */
const branchGraph = () => ({
    nodes: [node('t', 'manual'), node('br', 'branch'), node('b', 'send_email')],
    edges: [edge('t', 'default', 'br'), edge('br', 'true', 'b')],
});

function mountEditor(graph = linearGraph(), extraProps = {}) {
    const wrapper = mount(Edit, {
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
                ...graph,
            },
            ...extraProps,
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

    const canvas = () => wrapper.findComponent(CanvasStub);
    const panel = () => wrapper.findComponent(ConfigPanelStub);
    const button = (label) => wrapper.find(`[data-attr-aria-label="${label}"]`);

    return {
        wrapper,
        canvas,
        panel,
        button,
        // What the canvas is handed is what the canvas paints.
        nodeKeys: () => canvas().props('nodes').map((n) => n.node_key),
        labelOf: (key) => canvas().props('nodes').find((n) => n.node_key === key)?.label,
        save: () => wrapper.find('[data-attr-text="Save"]').trigger('click'),
    };
}

describe('Automations/Edit', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('never hands two nodes the same key, even when the RNG repeats itself', async () => {
        // `node_key` carries unique(automation_id, node_key). The generator is
        // four random base-36 characters and nothing checked the draw, so a
        // repeat became an SQL error on save — on a graph already built. A
        // frozen RNG is the deterministic version of that collision.
        const editor = mountEditor();
        vi.spyOn(Math, 'random').mockReturnValue(0.5);

        editor.canvas().vm.$emit('duplicate-node', 'a');
        await flushPromises();
        editor.canvas().vm.$emit('duplicate-node', 'a');
        await flushPromises();

        const keys = editor.nodeKeys();
        expect(keys).toHaveLength(5);
        expect(new Set(keys).size).toBe(keys.length);
    });

    it('brings a deleted node back after the user has typed a name in between', async () => {
        // The reported defect: history recorded one snapshot per keystroke, so
        // the delete was pushed out of reach by the typing that followed it.
        const editor = mountEditor();

        editor.canvas().vm.$emit('select', 'b');
        await flushPromises();
        editor.canvas().vm.$emit('remove-node', 'a');
        await flushPromises();
        expect(editor.nodeKeys()).toEqual(['t', 'b']);

        for (const label of ['W', 'We', 'Wel', 'Welc', 'Welco', 'Welcome']) {
            editor.panel().vm.$emit('update:label', label);
        }
        await flushPromises();
        expect(editor.labelOf('b')).toBe('Welcome');

        await editor.button('Undo').trigger('click');
        expect(editor.labelOf('b')).toBe('send_email');
        expect(editor.nodeKeys()).toEqual(['t', 'b']);

        await editor.button('Undo').trigger('click');
        expect(editor.nodeKeys()).toEqual(['t', 'a', 'b']);
    });

    it('stops undo at the last save instead of walking behind it', async () => {
        const editor = mountEditor();

        editor.canvas().vm.$emit('remove-node', 'a');
        await flushPromises();
        expect(editor.button('Undo').attributes('data-attr-disabled')).toBe('false');

        await editor.save();
        await flushPromises();

        expect(editor.button('Undo').attributes('data-attr-disabled')).toBe('true');

        await editor.button('Undo').trigger('click');
        expect(editor.nodeKeys()).toEqual(['t', 'b']);
    });

    /**
     * The mail list is a second reading of the same automation, wired into the
     * same page. What these two cover is the seam: the view switch, and that an
     * edit made from the list travels to the list endpoints — with the whole
     * order, which is the only thing ChainEditor::reorder accepts.
     */
    describe('the mail list view', () => {
        const mailListUrl = '/cp/automations/api/automations/3/mail-list';

        const mailList = () => ({
            mails: [
                {
                    position: 0, node_key: 'm1', type: 'send_email', label: 'One', reference: null,
                    disabled: false, delay: { seconds: 0, sources: [] }, conditional: false,
                    condition: null, also_runs: [],
                },
                {
                    position: 1, node_key: 'm2', type: 'send_email', label: 'Two', reference: null,
                    disabled: false, delay: { seconds: 2 * 86400, sources: ['d1'] }, conditional: false,
                    condition: null, also_runs: [],
                },
            ],
            editable: true,
            reasons: [],
            trigger: 't',
            tail: [],
        });

        async function openMailList(editor) {
            editor.wrapper.findComponent({ name: 'ToggleGroup' }).vm.$emit('update:modelValue', 'mails');
            await flushPromises();
        }

        it('reads the mails of the automation it is already showing', async () => {
            const editor = mountEditor(linearGraph(), { mailList: mailList(), mailListUrl });

            // The canvas is the default view; the list is one press away.
            expect(editor.wrapper.find('[data-mail-list]').exists()).toBe(false);

            await openMailList(editor);

            expect(editor.wrapper.findAll('[data-mail-row]')).toHaveLength(2);
            expect(editor.wrapper.find('[data-mail-delay="m2"]').text())
                .toBe('Sent 2 days after the previous mail');
        });

        it('sends the whole order to the reorder endpoint', async () => {
            const editor = mountEditor(linearGraph(), { mailList: mailList(), mailListUrl });
            await openMailList(editor);

            axios.post.mockResolvedValueOnce({ data: mailList() });

            await editor.wrapper.findAll('[data-attr-icon="arrow-down"]')[0].trigger('click');
            await flushPromises();

            expect(axios.post).toHaveBeenCalledWith(
                `${mailListUrl}/reorder`,
                { order: ['m2', 'm1'] },
            );
        });

        it('confirms a delete through the CP modal before it deletes anything', async () => {
            const editor = mountEditor(linearGraph(), { mailList: mailList(), mailListUrl });
            await openMailList(editor);

            await editor.wrapper.findAll('[data-attr-icon="trash"]')[1].trigger('click');
            await flushPromises();

            const modal = editor.wrapper.findComponent({ name: 'ConfirmationModal' });
            expect(modal.exists()).toBe(true);
            // It says what travels with the mail, because deleting one also
            // deletes the waiting time in front of it.
            expect(modal.attributes('data-attr-body-text')).toContain('“Two”');
            expect(axios.delete).not.toHaveBeenCalled();

            axios.delete.mockResolvedValueOnce({ data: mailList() });
            modal.vm.$emit('confirm');
            await flushPromises();

            expect(axios.delete).toHaveBeenCalledWith(`${mailListUrl}/m2`);
        });

        it('refuses to edit the list while the canvas holds unsaved changes', async () => {
            const editor = mountEditor(linearGraph(), { mailList: mailList(), mailListUrl });

            // A list edit is written straight to the stored automation. Doing
            // that under unsaved node edits means the next Save writes the old
            // order back over it.
            editor.canvas().vm.$emit('remove-node', 'a');
            await flushPromises();
            await openMailList(editor);

            expect(editor.wrapper.find('[data-mail-list-dirty]').exists()).toBe(true);
            expect(editor.wrapper.findAll('[data-attr-icon="arrow-down"]')).toHaveLength(0);
        });
    });

    it('duplicates a branch node onto an output the branch has', async () => {
        const editor = mountEditor(branchGraph());

        editor.canvas().vm.$emit('duplicate-node', 'br');
        await flushPromises();

        const outgoing = editor.canvas().props('edges').filter((e) => e.from_node_key === 'br');
        expect(outgoing).toHaveLength(1);
        // `default` here is what FlowValidator rejects as branch_invalid_output.
        expect(outgoing[0].from_output).toBe('true');

        const copyKey = outgoing[0].to_node_key;
        expect(editor.nodeKeys()).toContain(copyKey);
        expect(editor.canvas().props('edges').filter((e) => e.from_node_key === copyKey)).toEqual([
            expect.objectContaining({ from_output: 'true', to_node_key: 'b' }),
        ]);
    });
});
