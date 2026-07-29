import { describe, expect, it, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

import Canvas from '../../resources/js/components/builder/Canvas.vue';
import { computeLayout, fractionForOutput, handleY } from '../../resources/js/composables/useAutoLayout.js';
import { clearNodeOutputSpecs } from '../../resources/js/composables/useNodeOutputs.js';
import { useBuiltInOutputs } from './fixtures/built-in-library.mjs';

/**
 * The promise this release is for, checked on the thing the user actually
 * touches: a node type this package has never heard of declares three
 * outputs, and the canvas gives it three connectable handles.
 *
 * Handles are the only way an edge can be made in this builder. Before 1.7.0
 * `outputsFor()` knew four type names and gave everything else one `default`
 * handle, so a custom node's second and third outputs did not exist as far as
 * the canvas was concerned — whatever its PHP class declared and whatever the
 * runner would have routed.
 */

const REVIEW_SPEC = {
    version: 1,
    clauses: [{
        outputs: [
            { handle: 'approved', label: 'Approved' },
            { handle: 'rejected', label: 'Rejected' },
            { handle: 'escalated', label: 'Escalated' },
        ],
    }],
    primary: 'approved',
};

const reviewNode = {
    node_key: 'acme_review_1',
    type: 'acme.review',
    label: 'Review',
    config: {},
    disabled: false,
};

// Vue Flow constructs a ResizeObserver on mount; jsdom has none, and without
// this the mount throws into an unhandled rejection and the NEXT mount in the
// file comes back without a component instance (see 1.6.1's note).
globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

// Same class of problem, same cost if left out: Vue Flow measures its edge
// markers with SVGElement.getBBox(), which jsdom does not implement. The
// throw lands in a post-render hook, so the mount that fails is silent and
// the FOLLOWING one in the file returns a wrapper with nothing in it.
if (!globalThis.SVGElement.prototype.getBBox) {
    globalThis.SVGElement.prototype.getBBox = () => ({ x: 0, y: 0, width: 0, height: 0 });
}

/**
 * Mount the real canvas — Vue Flow, the node cards and Vue Flow's own
 * `Handle` components, not a stub of them. `data-handleid` is Vue Flow's
 * own attribute on a rendered handle, so what is asserted is the connection
 * point a user can actually drag from.
 */
async function canvas(nodes, edges = []) {
    const wrapper = mount(Canvas, {
        props: { nodes, edges, library: { triggers: [], logic: [], actions: [] } },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

/** The source handles Vue Flow rendered on one node, in DOM order. */
const sourceHandles = (wrapper, nodeKey) =>
    wrapper
        .findAll(`.vue-flow__node[data-id="${nodeKey}"] .vue-flow__handle.source`)
        .map((el) => el.attributes('data-handleid'));

describe('a third-party node with three declared outputs', () => {
    beforeEach(() => clearNodeOutputSpecs());

    it('renders one connectable source handle per declared output', async () => {
        useBuiltInOutputs({ 'acme.review': REVIEW_SPEC });

        expect(sourceHandles(await canvas([reviewNode]), 'acme_review_1')).toEqual(['approved', 'rejected', 'escalated']);
    });

    it('gets one default handle when the library has not declared it', async () => {
        // Same node, no declaration: exactly what every custom node got
        // before this release, and what the canvas still falls back to.
        useBuiltInOutputs();

        expect(sourceHandles(await canvas([reviewNode]), 'acme_review_1')).toEqual(['default']);
    });

    it('spreads its handles evenly, and the "+" adders land on the same fractions', async () => {
        useBuiltInOutputs({ 'acme.review': REVIEW_SPEC });

        const wrapper = await canvas([reviewNode]);
        const lefts = wrapper
            .findAll('.vue-flow__node[data-id="acme_review_1"] .vue-flow__handle.source')
            .map((el) => el.attributes('style'));

        expect(lefts).toEqual([
            expect.stringContaining('left: 25%'),
            expect.stringContaining('left: 50%'),
            expect.stringContaining('left: 75%'),
        ]);

        // Canvas.vue positions the adder from fractionForOutput() while the
        // card positions the dot from handleY() — they must not drift.
        ['approved', 'rejected', 'escalated'].forEach((handle, i) => {
            expect(fractionForOutput(reviewNode, handle)).toBe(handleY(i, 3));
        });
    });

    it('offers an append point on every one of its outputs', async () => {
        useBuiltInOutputs({ 'acme.review': REVIEW_SPEC });

        const { openOutputs } = computeLayout([reviewNode], []);

        expect(openOutputs.map((o) => o.from_output)).toEqual(['approved', 'rejected', 'escalated']);
    });

    it('lays its wired outputs out left to right in declaration order', async () => {
        useBuiltInOutputs({ 'acme.review': REVIEW_SPEC });

        const target = (key) => ({ node_key: key, type: 'send_email', config: {} });
        const { positions } = computeLayout(
            [reviewNode, target('a'), target('b'), target('c')],
            [
                { from_node_key: 'acme_review_1', from_output: 'escalated', to_node_key: 'c' },
                { from_node_key: 'acme_review_1', from_output: 'approved', to_node_key: 'a' },
                { from_node_key: 'acme_review_1', from_output: 'rejected', to_node_key: 'b' },
            ],
        );

        // Column order follows the node's declaration, not the order the
        // edges happen to be stored in.
        expect(positions.a.x).toBeLessThan(positions.b.x);
        expect(positions.b.x).toBeLessThan(positions.c.x);
    });

    it('renders the built-in loop with its own two handles from the same payload', async () => {
        useBuiltInOutputs();

        const loop = { node_key: 'loop_1', type: 'loop', label: 'Loop', config: {}, disabled: false };

        expect(sourceHandles(await canvas([loop]), 'loop_1')).toEqual(['loop', 'done']);
    });

    it('follows a switch while its cases are being typed', async () => {
        useBuiltInOutputs();

        const node = (cases) => ({ node_key: 'switch_1', type: 'switch', label: 'Switch', config: { cases }, disabled: false });

        expect(sourceHandles(await canvas([node({})]), 'switch_1')).toEqual(['default']);
        expect(sourceHandles(await canvas([node({ de: 'german' })]), 'switch_1')).toEqual(['german', 'default']);
        expect(sourceHandles(await canvas([node({ de: 'german', en: 'english' })]), 'switch_1'))
            .toEqual(['german', 'english', 'default']);
    });
});
