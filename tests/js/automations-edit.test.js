import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';

import { builtInLibrary } from './fixtures/built-in-library.mjs';

vi.mock('axios', () => ({
    default: {
        patch: vi.fn(async () => ({ data: { data: {} } })),
        post: vi.fn(async () => ({ data: { data: { id: 1, handle: 'flow' } } })),
        get: vi.fn(async () => ({ data: {} })),
    },
}));

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

function mountEditor(graph = linearGraph()) {
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
