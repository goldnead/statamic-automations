import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

import {
    continuationOutput,
    uniqueNodeKey,
    useGraphMutations,
} from '../../resources/js/composables/useGraphMutations.js';
import { useHistory } from '../../resources/js/composables/useHistory.js';

/**
 * The builder's graph mutations, driven exactly as Edit.vue drives them.
 *
 * These lived inline in the page until 1.5.5. What they get wrong is not
 * visible in a rendered DOM and not reachable from PHPUnit: the graph they
 * hand to the backend is wrong — an edge on an output its node does not have,
 * a node key that collides with one already in the automation.
 */

const library = {
    triggers: [{ handle: 'manual', label: 'Manual', schema: [] }],
    logic: [
        { handle: 'branch', label: 'Branch', schema: [] },
        { handle: 'loop', label: 'Loop', schema: [] },
        { handle: 'parallel', label: 'Parallel', schema: [] },
        { handle: 'acme.branch', label: 'Acme branch', schema: [] },
        { handle: 'stop', label: 'Stop', schema: [] },
    ],
    actions: [{ handle: 'send_email', label: 'Send email', schema: [] }],
};

function harness(graph) {
    const automation = ref({ id: 1, name: 'Flow', ...graph });
    const selectedNodeKey = ref(null);
    const notify = vi.fn();
    const history = useHistory({
        getState: () => ({ nodes: automation.value.nodes, edges: automation.value.edges }),
        setState: (state) => {
            automation.value = { ...automation.value, nodes: state.nodes, edges: state.edges };
        },
    });

    return {
        automation,
        selectedNodeKey,
        notify,
        history,
        mutations: useGraphMutations({ automation, selectedNodeKey, library, history, notify }),
        edgesFrom: (key) => automation.value.edges.filter((e) => e.from_node_key === key),
        node: (key) => automation.value.nodes.find((n) => n.node_key === key),
    };
}

const node = (node_key, type, config = {}) => ({
    node_key,
    type,
    label: type,
    position_x: 0,
    position_y: 0,
    config,
    disabled: false,
});

const edge = (from, output, to) => ({
    from_node_key: from,
    from_output: output,
    to_node_key: to,
    to_input: 'default',
});

/**
 * The output rule every insertion now follows. `FlowValidator` accepts only
 * `true`/`false` off a branch node (src/Engine/FlowValidator.php), and
 * `WorkflowRunner::nextNode()` matches `from_output` exactly, so an edge on a
 * handle the node does not declare is both invalid and dead.
 */
describe('continuationOutput', () => {
    it('is the node\'s first declared output, never a hard-coded default', () => {
        expect(continuationOutput({ type: 'send_email' })).toBe('default');
        expect(continuationOutput({ type: 'branch' })).toBe('true');
        expect(continuationOutput({ type: 'acme.branch' })).toBe('true');
        expect(continuationOutput({ type: 'loop' })).toBe('loop');
        expect(continuationOutput({ type: 'parallel', config: { branches: { a: 'A', b: 'B' } } })).toBe('a');
    });

    it('is null for a node that declares no outputs at all', () => {
        expect(continuationOutput({ type: 'stop' })).toBeNull();
        expect(continuationOutput({ type: 'parallel', config: {} })).toBeNull();
    });
});

describe('uniqueNodeKey', () => {
    it('never returns a key the automation already holds', () => {
        // `node_key` carries unique(automation_id, node_key): a repeat is an
        // SQL error on save, on a graph the user has already built.
        const taken = ['send_email_aaaa', 'send_email_bbbb'];
        const draws = ['aaaa', 'bbbb', 'cccc'];
        const key = uniqueNodeKey('send_email', taken, () => draws.shift());

        expect(key).toBe('send_email_cccc');
    });

    it('terminates on a counter when the random draw keeps colliding', () => {
        const taken = new Set(['x_zz', 'x_2', 'x_3']);
        const key = uniqueNodeKey('x', taken, () => 'zz');

        expect(key).toBe('x_4');
    });

    it('sanitises the handle the way node keys have always been built', () => {
        expect(uniqueNodeKey('acme.branch', [], () => 'q1w2')).toBe('acme_branch_q1w2');
    });
});

describe('duplicateNode', () => {
    it('hangs the copy of a branch node off a handle the branch actually has', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('b1', 'branch')],
            edges: [edge('t', 'default', 'b1')],
        });

        h.mutations.duplicateNode('b1');

        const outgoing = h.edgesFrom('b1');
        expect(outgoing).toHaveLength(1);
        // Pre-1.5.5 this was `default`, which FlowValidator rejects with
        // `branch_invalid_output` — one click, invalid graph.
        expect(outgoing[0].from_output).toBe('true');

        const copy = h.automation.value.nodes.find((n) => n.node_key === outgoing[0].to_node_key);
        expect(copy.type).toBe('branch');
        expect(copy.node_key).not.toBe('b1');
    });

    it('splits the branch\'s own output when that output is already wired', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('b1', 'branch'), node('m', 'send_email')],
            edges: [edge('t', 'default', 'b1'), edge('b1', 'true', 'm')],
        });

        h.mutations.duplicateNode('b1');

        const [outgoing] = h.edgesFrom('b1');
        const copyKey = outgoing.to_node_key;
        expect(outgoing.from_output).toBe('true');
        expect(copyKey).not.toBe('m');
        // The copy is a branch too, so its own onward edge leaves `true`.
        expect(h.edgesFrom(copyKey)).toEqual([
            expect.objectContaining({ from_output: 'true', to_node_key: 'm' }),
        ]);
    });

    it('keeps the plain-node case exactly as it was', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('m', 'send_email'), node('m2', 'send_email')],
            edges: [edge('t', 'default', 'm'), edge('m', 'default', 'm2')],
        });

        h.mutations.duplicateNode('m');

        const [outgoing] = h.edgesFrom('m');
        expect(outgoing.from_output).toBe('default');
        expect(h.edgesFrom(outgoing.to_node_key)).toEqual([
            expect.objectContaining({ from_output: 'default', to_node_key: 'm2' }),
        ]);
    });

    it('adds the copy unconnected when the source has no output to hang it off', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('s', 'stop')],
            edges: [edge('t', 'default', 's')],
        });

        h.mutations.duplicateNode('s');

        expect(h.automation.value.nodes).toHaveLength(3);
        expect(h.edgesFrom('s')).toHaveLength(0);
        expect(h.notify).toHaveBeenCalledWith('info', expect.stringContaining('unconnected'));
    });

    it('gives the copy a key the automation does not already hold', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('m', 'send_email')],
            edges: [edge('t', 'default', 'm')],
        });

        for (let i = 0; i < 25; i++) h.mutations.duplicateNode('m');

        const keys = h.automation.value.nodes.map((n) => n.node_key);
        expect(new Set(keys).size).toBe(keys.length);
    });
});

describe('insertOnEdge', () => {
    it('wires a branch dropped onto an existing edge on its own true output', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('m', 'send_email')],
            edges: [edge('t', 'default', 'm')],
        });

        h.mutations.insertNode('branch', {
            kind: 'insert',
            edge: { from_node_key: 't', from_output: 'default', to_node_key: 'm' },
        });

        const inserted = h.selectedNodeKey.value;
        expect(h.edgesFrom('t')).toEqual([
            expect.objectContaining({ from_output: 'default', to_node_key: inserted }),
        ]);
        expect(h.edgesFrom(inserted)).toEqual([
            expect.objectContaining({ from_output: 'true', to_node_key: 'm' }),
        ]);
    });

    it('leaves no edge behind a node that has no outputs', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('m', 'send_email')],
            edges: [edge('t', 'default', 'm')],
        });

        h.mutations.insertNode('stop', {
            kind: 'insert',
            edge: { from_node_key: 't', from_output: 'default', to_node_key: 'm' },
        });

        // A `stop` ends the run; an onward edge from it is invisible on the
        // canvas and never followed by the runner.
        expect(h.edgesFrom(h.selectedNodeKey.value)).toHaveLength(0);
    });
});

describe('history tagging', () => {
    it('folds a burst of label typing into one undo step, behind the structural one', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('a', 'send_email'), node('b', 'send_email')],
            edges: [edge('t', 'default', 'a'), edge('a', 'default', 'b')],
        });

        h.mutations.removeNode('a');
        h.selectedNodeKey.value = 'b';
        for (const label of ['W', 'We', 'Wel', 'Welc', 'Welco', 'Welcome']) {
            h.mutations.updateNodeLabel(label);
        }

        h.history.undo();
        expect(h.node('b').label).toBe('send_email');
        expect(h.node('a')).toBeUndefined();

        h.history.undo();
        expect(h.node('a')).toBeDefined();
    });

    it('starts a new undo step when the typing moves to another node', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('a', 'send_email'), node('b', 'send_email')],
            edges: [edge('t', 'default', 'a'), edge('a', 'default', 'b')],
        });

        h.selectedNodeKey.value = 'a';
        h.mutations.updateNodeLabel('one');
        h.selectedNodeKey.value = 'b';
        h.mutations.updateNodeLabel('two');

        h.history.undo();
        expect(h.node('b').label).toBe('send_email');
        expect(h.node('a').label).toBe('one');
    });

    it('never folds two structural steps together', () => {
        const h = harness({
            nodes: [node('t', 'manual'), node('a', 'send_email'), node('b', 'send_email')],
            edges: [edge('t', 'default', 'a'), edge('a', 'default', 'b')],
        });

        h.mutations.toggleNodeDisabled('a');
        h.mutations.toggleNodeDisabled('b');

        h.history.undo();
        expect(h.node('b').disabled).toBe(false);
        expect(h.node('a').disabled).toBe(true);
    });
});
