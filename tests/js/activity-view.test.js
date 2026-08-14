import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';

import { builtInLibrary } from './fixtures/built-in-library.mjs';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async () => ({ data: {} })),
        post: vi.fn(async () => ({ data: {} })),
        patch: vi.fn(async () => ({ data: { data: {} } })),
        delete: vi.fn(async () => ({ data: {} })),
    },
}));

import axios from 'axios';

import Edit from '../../resources/js/pages/Automations/Edit.vue';
import ActivityPanel from '../../resources/js/components/activity/ActivityPanel.vue';
import NodeCard from '../../resources/js/components/builder/NodeCard.vue';

/**
 * The third reading of an automation: what it has actually been doing.
 *
 * What is checked here is the shape that breaks — an automation nobody has been
 * through — and the seam between the three parts: the switcher, the canvas that
 * paints the same numbers, and the window that governs all of it.
 */

const stub = (name, props, emits) => defineComponent({ name, props, emits, setup: () => () => h('div') });

const CanvasStub = stub(
    'CanvasStub',
    ['nodes', 'edges', 'selectedKey', 'validation', 'nodeStats', 'library', 'pendingTarget'],
    ['select', 'remove-node', 'duplicate-node'],
);
const ConfigPanelStub = stub('ConfigPanelStub', ['node', 'library'], ['update:label', 'update:config']);

const HeaderStub = defineComponent({
    name: 'HeaderStub',
    setup: (_props, { slots }) => () => h('div', Object.values(slots).map((slot) => slot())),
});

// NodeCard imports Vue Flow's Handle, which constructs a ResizeObserver on
// mount; jsdom has none, and without this the mount throws into an unhandled
// rejection and the NEXT mount in the file comes back with nothing in it.
globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

const library = builtInLibrary();

const node = (node_key, type) => ({
    node_key, type, label: type, position_x: 0, position_y: 0, config: {}, disabled: false,
});

const edge = (from, to) => ({
    from_node_key: from, from_output: 'default', to_node_key: to, to_input: 'default',
});

const graph = () => ({
    nodes: [node('t', 'manual'), node('a', 'send_email'), node('b', 'send_email')],
    edges: [edge('t', 'a'), edge('a', 'b')],
});

const activity = (overrides = {}) => ({
    range: '30',
    ranges: [
        { value: '7', label: 'Last 7 days' },
        { value: '30', label: 'Last 30 days' },
        { value: 'all', label: 'All time' },
    ],
    funnel: { enrolled: 0, in_progress: 0, completed: 0, exited: 0, failed: 0 },
    nodes: {},
    without_subject: 0,
    statusOptions: [{ value: '', label: 'Any outcome' }],
    logColumns: [],
    subjectColumns: [],
    overviewUrl: '/cp/automations/api/automations/3/activity',
    logUrl: '/cp/automations/api/automations/3/activity/node-runs',
    subjectsUrl: '/cp/automations/api/automations/3/activity/subjects',
    exportUrl: '/cp/automations/api/automations/3/activity/export',
    ...overrides,
});

function mountEditor(extraProps = {}) {
    return mount(Edit, {
        props: {
            mode: 'edit',
            title: 'Flow',
            apiBase: '/cp/automations/api',
            indexUrl: '/cp/automations',
            library,
            automation: { id: 3, handle: 'flow', name: 'Flow', description: null, enabled: false, ...graph() },
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
}

function mountPanel(props = {}) {
    return mount(ActivityPanel, {
        props: { activity: activity(), nodes: graph().nodes, edges: graph().edges, ...props },
    });
}

describe('the activity view', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => vi.restoreAllMocks());

    it('is a third value of the view switcher, not a screen of its own', async () => {
        const editor = mountEditor({ activity: activity() });

        expect(editor.find('[data-activity]').exists()).toBe(false);

        editor.findComponent({ name: 'ToggleGroup' }).vm.$emit('update:modelValue', 'activity');
        await flushPromises();

        expect(editor.find('[data-activity]').exists()).toBe(true);
    });

    it('is not offered at all to somebody who may not read runs', () => {
        // The server withholds the prop; the switcher must not advertise a view
        // whose every endpoint would answer 403.
        const editor = mountEditor({ activity: null });

        const values = editor.findAllComponents({ name: 'ToggleItem' })
            .map((item) => item.attributes('data-attr-value'));

        expect(values).not.toContain('activity');
    });

    it('renders an automation nobody has been through without a single NaN', async () => {
        // The shape that breaks a funnel: nothing to be a percentage of.
        const panel = mountPanel();

        await flushPromises();

        expect(panel.text()).not.toContain('NaN');
        expect(panel.find('[data-activity-step]').exists()).toBe(false);
        expect(panel.text()).toContain('Nobody has been through this automation in this timeframe.');
        expect(panel.find('[data-activity-tile="enrolled"]').attributes('data-attr-text')).toBe('0');
    });

    it('measures every step against the busiest one', async () => {
        const panel = mountPanel({
            activity: activity({
                funnel: { enrolled: 100, in_progress: 10, completed: 60, exited: 25, failed: 5 },
                nodes: {
                    t: { reached: 100, completed: 100, failed: 0 },
                    a: { reached: 80, completed: 75, failed: 5 },
                    b: { reached: 60, completed: 60, failed: 0 },
                },
            }),
        });

        await flushPromises();

        const steps = panel.findAll('[data-activity-step]');

        expect(steps).toHaveLength(3);
        // The order is the canvas order, taken from the same layout the canvas
        // uses rather than from a second traversal that could disagree.
        expect(steps.map((s) => s.attributes('data-activity-step'))).toEqual(['t', 'a', 'b']);
        expect(steps[1].text()).toContain('80%');
        expect(panel.find('[data-activity-step-detail="a"]').text())
            .toBe('75 got through it · 5 failed here');
    });

    it('keeps a step whose node has been deleted, and says so', async () => {
        const panel = mountPanel({
            activity: activity({
                nodes: {
                    t: { reached: 10, completed: 10, failed: 0 },
                    gone: { reached: 4, completed: 4, failed: 0 },
                },
            }),
        });

        await flushPromises();

        const steps = panel.findAll('[data-activity-step]');

        // Appended rather than dropped: it is a thing that happened, and a
        // funnel that loses a step it no longer recognises lies about where
        // people went.
        expect(steps.map((s) => s.attributes('data-activity-step'))).toEqual(['t', 'a', 'b', 'gone']);
        expect(steps[3].text()).toContain('No longer in the flow');
    });

    it('re-asks the server when the window changes and tells the canvas', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                range: '7',
                funnel: { enrolled: 3, in_progress: 1, completed: 2, exited: 0, failed: 0 },
                nodes: { a: { reached: 3, completed: 2, failed: 0 } },
                without_subject: 0,
            },
        });

        const panel = mountPanel({
            activity: activity({ nodes: { a: { reached: 90, completed: 90, failed: 0 } } }),
        });

        panel.find('[data-activity-range]').trigger('update:modelValue');
        await panel.findComponent({ name: 'Select' }).vm.$emit('update:modelValue', '7');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(
            '/cp/automations/api/automations/3/activity',
            { params: { range: '7' } },
        );

        const emitted = panel.emitted('update:nodes-stats');

        // The canvas paints the same figures, so a changed window has to reach
        // it — and a node that dropped out of the new window must lose its
        // numbers rather than keep the old ones.
        expect(emitted.at(-1)[0]).toEqual({ a: { reached: 3, completed: 2, failed: 0 } });
        expect(panel.find('[data-activity-tile="enrolled"]').attributes('data-attr-text')).toBe('3');
    });

    it('hands the canvas whatever the panel last said', async () => {
        const editor = mountEditor({
            activity: activity({ nodes: { a: { reached: 7, completed: 7, failed: 0 } } }),
        });

        await flushPromises();

        expect(editor.findComponent(CanvasStub).props('nodeStats'))
            .toEqual({ a: { reached: 7, completed: 7, failed: 0 } });
    });
});

describe('the numbers on a node card', () => {
    const card = (stats) => mount(NodeCard, {
        props: {
            kind: 'action',
            data: { label: 'Welcome', type: 'send_email', config: {}, disabled: false },
            stats,
        },
        global: {
            stubs: {
                Handle: true,
            },
        },
    });

    it('shows nothing at all for a step nothing has run through', () => {
        // A fresh automation whose every card reads "0 / 0 / 0" looks broken
        // rather than new.
        expect(card(null).find('[data-node-stats]').exists()).toBe(false);
    });

    it('shows what reached it and what got through it', () => {
        const wrapper = card({ reached: 128, completed: 120, failed: 0 });
        const strip = wrapper.find('[data-node-stats]');

        expect(strip.exists()).toBe(true);
        expect(strip.text()).toContain('128');
        expect(strip.text()).toContain('120');
        // No failures, so no failure figure — the card is 240px wide and a zero
        // that is always there is a zero nobody reads.
        expect(strip.findAll('span').some((s) => s.text() === '0')).toBe(false);
    });

    it('abbreviates a figure that would not fit', () => {
        expect(card({ reached: 12437, completed: 12000, failed: 3 }).find('[data-node-stats]').text())
            .toContain('12k');
    });
});
