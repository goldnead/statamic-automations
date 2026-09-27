/**
 * The Loop frame, from this addon's side: that the scope this addon declares
 * for its Loop node turns a real-shaped automation into the right frame, and
 * that none of it can reach the saved graph.
 *
 * The geometry and the edge cases of the graph walk are tested where the code
 * lives, in `@goldnead/flow-canvas` (tests/js/scope-frames.test.js). What is
 * pinned here is the seam: the handles `loop` / `done` from LoopNode.php, the
 * wording, and the storage key.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    SCOPE_FRAME,
    collapseScopes,
    computeScopeFrames,
    layoutScoped,
    visibleScopeFrames,
    setNodeOutputSpecs,
    clearNodeOutputSpecs,
} from '@goldnead/flow-canvas';
import { SCOPES, SCOPE_LABELS, scopeViewStateKey } from '../../resources/js/support/nodeKinds.js';
import specs from './fixtures/node-output-specs.json';

const node = (key, type = 'set_variable', label = key) => ({ node_key: key, type, label, config: {} });
const edge = (from, to, output = 'default') => ({ from_node_key: from, from_output: output, to_node_key: to, to_input: 'default' });

/**
 * Shaped like the calendar sync that prompted this (a scheduled trigger, three
 * steps, then a Loop whose body is a thirteen-step chain and whose `done` is
 * left open). Keys are made up; the shape is the point.
 */
function calendarSync() {
    const body = Array.from({ length: 13 }, (_, i) => `step_${i + 1}`);
    const nodes = [
        node('every_15_minutes', 'scheduled'),
        node('fetch_events', 'set_variable'),
        node('read_sheets', 'set_variable'),
        node('remember_ids', 'set_variable'),
        node('each_event', 'loop', 'Für jeden Termin'),
        ...body.map((key, i) => node(key, i === 0 ? 'filter' : 'set_variable')),
    ];
    const edges = [
        edge('every_15_minutes', 'fetch_events'),
        edge('fetch_events', 'read_sheets'),
        edge('read_sheets', 'remember_ids'),
        edge('remember_ids', 'each_event'),
        edge('each_event', body[0], 'loop'),
        ...body.slice(1).map((key, i) => edge(body[i], key)),
    ];
    return { nodes, edges, body };
}

beforeEach(() => setNodeOutputSpecs(Object.entries(specs).map(([handle, outputs]) => ({ handle, outputs }))));
afterEach(() => clearNodeOutputSpecs());

describe('the Loop scope this addon declares', () => {
    it('uses the handles LoopNode.php declares', () => {
        expect(SCOPES).toEqual({ loop: { output: 'loop', continuation: 'done' } });
        const outputs = specs.loop.clauses[0].outputs.map((o) => o.handle);
        expect(outputs).toEqual([SCOPES.loop.output, SCOPES.loop.continuation]);
    });

    it('frames the whole body of the calendar sync, and nothing before it', () => {
        const { nodes, edges, body } = calendarSync();
        const frames = computeScopeFrames(nodes, edges, SCOPES);

        expect(frames).toHaveLength(1);
        expect(frames[0].owner).toBe('each_event');
        expect(frames[0].members).toEqual(body);
        expect(frames[0].terminals).toEqual(['step_13']);
        expect(SCOPE_LABELS.steps(frames[0].members.length)).toBe('13 steps');
    });

    it('folds the body into one block and keeps the rest of the flow', () => {
        const { nodes, edges } = calendarSync();
        const frames = computeScopeFrames(nodes, edges, SCOPES);
        const view = collapseScopes(nodes, edges, frames, ['each_event']);

        expect(view.nodes).toHaveLength(6);
        expect(view.nodes.map((n) => n.node_key).slice(0, 5)).toEqual([
            'every_15_minutes', 'fetch_events', 'read_sheets', 'remember_ids', 'each_event',
        ]);

        // Laid out, the block hangs under the Loop, inside the Loop's frame.
        const shown = visibleScopeFrames(frames, view, ['each_event']);
        const { positions, rects } = layoutScoped(view.nodes, view.edges, shown);
        const block = view.blocks[0].id;
        expect(positions[block].y).toBeGreaterThan(positions.each_event.y);
        expect(positions[block].y).toBeLessThan(rects.each_event.y + rects.each_event.height);
    });

    it('puts a step wired to "After loop" under the whole frame', () => {
        const { nodes, edges } = calendarSync();
        nodes.push(node('after'));
        edges.push(edge('each_event', 'after', 'done'));
        const frames = computeScopeFrames(nodes, edges, SCOPES);
        const view = collapseScopes(nodes, edges, frames, []);
        const { positions, rects } = layoutScoped(view.nodes, view.edges, visibleScopeFrames(frames, view, []));
        const frame = rects.each_event;

        expect(positions.after.y).toBe(frame.y + frame.height + SCOPE_FRAME.BELOW);
        expect(positions.step_13.y).toBeLessThan(frame.y + frame.height);
    });

    it('never writes into the graph it draws', () => {
        const { nodes, edges } = calendarSync();
        const saved = JSON.stringify({ nodes, edges });
        const frames = computeScopeFrames(nodes, edges, SCOPES);
        const view = collapseScopes(nodes, edges, frames, ['each_event']);
        layoutScoped(view.nodes, view.edges, visibleScopeFrames(frames, view, ['each_event']));
        layoutScoped(nodes, edges, visibleScopeFrames(frames, { hidden: new Set(), blocks: [] }, []));

        expect(JSON.stringify({ nodes, edges })).toBe(saved);
        expect(edges.some((e) => e.to_node_key === 'each_event' && e.from_node_key.startsWith('step_'))).toBe(false);
    });

    it('frames a legacy automation-mode Loop, which has no body, not at all', () => {
        const nodes = [node('t', 'scheduled'), node('l', 'loop'), node('after')];
        const edges = [edge('t', 'l'), edge('l', 'after', 'done')];
        expect(computeScopeFrames(nodes, edges, SCOPES)).toEqual([]);
    });
});

describe('wording and view state', () => {
    it('says one step, and more steps, and names the loop on its button', () => {
        expect(SCOPE_LABELS.steps(1)).toBe('1 step');
        expect(SCOPE_LABELS.steps(4)).toBe('4 steps');
        expect(SCOPE_LABELS.collapse('Für jeden Termin')).toBe('Collapse “Für jeden Termin”');
        expect(SCOPE_LABELS.expand('Für jeden Termin')).toBe('Expand “Für jeden Termin”');
    });

    it('remembers folds per automation, and not before it has been saved', () => {
        expect(scopeViewStateKey(42)).toBe('statamic-automations.canvas.42.collapsed');
        expect(scopeViewStateKey(null)).toBeNull();
        expect(scopeViewStateKey(undefined)).toBeNull();
    });
});
