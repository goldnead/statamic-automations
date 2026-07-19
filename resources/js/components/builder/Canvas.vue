<template>
    <VueFlow
        :id="flowId"
        v-model:nodes="vfNodes"
        v-model:edges="vfEdges"
        :nodes-draggable="false"
        :nodes-connectable="false"
        :edges-updatable="false"
        :elements-selectable="true"
        :select-nodes-on-drag="false"
        :pan-on-drag="true"
        :pan-activation-key-code="'Space'"
        :selection-key-code="'Shift'"
        :delete-key-code="null"
        :min-zoom="0.3"
        :max-zoom="1.5"
        class="size-full"
        @node-click="onNodeClick"
    >
        <template #node-trigger="slotProps">
            <NodeCard kind="trigger" v-bind="cardProps(slotProps)" v-on="cardHandlers(slotProps.id)" />
        </template>
        <template #node-action="slotProps">
            <NodeCard kind="action" v-bind="cardProps(slotProps)" v-on="cardHandlers(slotProps.id)" />
        </template>
        <template #node-logic="slotProps">
            <NodeCard kind="logic" v-bind="cardProps(slotProps)" v-on="cardHandlers(slotProps.id)" />
        </template>
        <template #node-adder="slotProps">
            <AdderNode :data="slotProps.data" />
        </template>

        <template #edge-insertable="edgeProps">
            <InsertableEdge v-bind="edgeProps" />
        </template>

        <Background :pattern-color="dotColor" :gap="18" :size="1.4" />
        <Controls />
        <MiniMap v-if="realNodeCount" pannable zoomable />

        <Panel position="bottom-left">
            <ControlBar :flow-id="flowId" />
        </Panel>
    </VueFlow>
</template>

<script setup>
import { computed, nextTick, provide, ref, watch } from 'vue';
import { VueFlow, Panel, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import NodeCard from './NodeCard.vue';
import ControlBar from './ControlBar.vue';
import AdderNode from './AdderNode.vue';
import InsertableEdge from './InsertableEdge.vue';
import { computeLayout, LAYOUT, fractionForOutput } from '../../composables/useAutoLayout.js';

const props = defineProps({
    nodes: { type: Array, required: true },
    edges: { type: Array, required: true },
    selectedKey: { type: String, default: null },
    validation: { type: Object, default: () => ({}) },
    library: { type: Object, default: () => ({ triggers: [], logic: [], actions: [] }) },
    // The pending sidebar "pick mode" target (see Edit.vue). Null when no "+"
    // is currently armed; otherwise `{kind:'append', fromNodeKey, output}` or
    // `{kind:'insert', edge}`. Passed through so adders can render their own
    // active/pending state without prop-drilling through NodeCard/VueFlow slots.
    pendingTarget: { type: Object, default: null },
});

const emit = defineEmits([
    'select',
    'toggle-pick',
    'remove-node',
    'rename-node',
    'duplicate-node',
    'toggle-node-disabled',
    'replace-trigger',
]);

// The adder components (append nodes + insertable edges) are rendered deep
// inside Vue Flow's slot templates. Provide the pending-pick state and a
// callback to arm/disarm it so they can reach back up to the page without
// prop drilling. Clicking a "+" no longer opens a dropdown — it just tells
// Edit.vue "pick mode is now targeting this spot"; the actual node choice
// happens in the left NodeLibrary sidebar (see fix-picker-sidebar-brief.md).
provide('saPendingTarget', computed(() => props.pendingTarget));
provide('saStartPick', (target) => emit('toggle-pick', target));

// Vue Flow paints the dots via an SVG `fill` attribute, which does not resolve
// CSS `var()`. Pass a neutral literal here and re-tint theme-aware from cp.css.
const dotColor = '#d1d5db';

function cardProps(slotProps) {
    return {
        data: slotProps.data,
        selected: slotProps.selected,
        status: statusFor(slotProps.id),
    };
}

function cardHandlers(id) {
    return {
        rename: () => emit('rename-node', id),
        duplicate: () => emit('duplicate-node', id),
        'toggle-disabled': () => emit('toggle-node-disabled', id),
        delete: () => emit('remove-node', id),
        'replace-trigger': () => emit('replace-trigger', id),
    };
}

// Scope this Vue Flow instance to a unique id so each builder session isolates
// its store and disposes cleanly on CP navigation.
const flowId = `sa-flow-${Math.random().toString(36).slice(2, 10)}`;

const vfNodes = ref([]);
const vfEdges = ref([]);
const realNodeCount = computed(() => props.nodes.length);

const { fitView, onNodesInitialized } = useVueFlow(flowId);

const ADDER_HALF = 18; // half the "+" button, to centre it under the handle
const ADDER_DROP = 150; // vertical offset from the node top to its adder

const ADDER_PREFIX = '__adder__';
const STUB_PREFIX = '__stub__';

function isSynthetic(id) {
    return id.startsWith(ADDER_PREFIX) || id.startsWith(STUB_PREFIX);
}

function nodeKind(type) {
    const inGroup = (group) => (props.library[group] ?? []).some((m) => m.handle === type);
    if (inGroup('triggers')) return 'trigger';
    if (inGroup('logic')) return 'logic';
    if (inGroup('actions')) return 'action';
    return 'action';
}

function labelFor(handle) {
    const lib = props.library;
    return [
        ...(lib.triggers ?? []),
        ...(lib.logic ?? []),
        ...(lib.actions ?? []),
    ].find((m) => m.handle === handle)?.label ?? handle;
}

function toVueFlowNode(n, position) {
    return {
        id: n.node_key,
        type: nodeKind(n.type),
        position: position ?? { x: 0, y: 0 },
        draggable: false,
        selected: n.node_key === props.selectedKey,
        data: {
            label: n.label || labelFor(n.type),
            type: n.type,
            config: n.config ?? {},
            disabled: n.disabled ?? false,
        },
    };
}

function adderNode(open, srcPos, node) {
    const frac = fractionForOutput(node, open.from_output);
    return {
        id: `${ADDER_PREFIX}${open.from_node_key}__${open.from_output}`,
        type: 'adder',
        draggable: false,
        selectable: false,
        connectable: false,
        deletable: false,
        focusable: false,
        position: {
            x: Math.round(srcPos.x + frac * LAYOUT.NODE_WIDTH - ADDER_HALF),
            y: srcPos.y + ADDER_DROP,
        },
        data: { fromNodeKey: open.from_node_key, output: open.from_output, mode: 'step' },
    };
}

function rootAdder() {
    return {
        id: `${ADDER_PREFIX}root`,
        type: 'adder',
        draggable: false,
        selectable: false,
        connectable: false,
        deletable: false,
        focusable: false,
        position: { x: -ADDER_HALF, y: 40 },
        data: { fromNodeKey: null, output: 'default', mode: 'trigger' },
    };
}

function toVueFlowEdge(e) {
    const out = e.from_output ?? 'default';
    const branch = out === 'true' || out === 'false';
    const accent = out === 'true'
        ? 'var(--sa-color-success)'
        : out === 'false' ? 'var(--sa-color-failed)' : null;

    return {
        id: `${e.from_node_key}__${out}__${e.to_node_key}`,
        source: e.from_node_key,
        target: e.to_node_key,
        // NodeCard renders one explicitly-`id`'d Handle per output (even the
        // lone "default" case), so the edge's sourceHandle must always name
        // it — Vue Flow can't resolve a `null` handle id against a real one.
        sourceHandle: out,
        type: 'insertable',
        data: { branch: branch ? out : null },
        style: accent ? { stroke: accent } : undefined,
    };
}

// A short dashed stub from an open output down to its "+" adder.
function stubEdge(open) {
    const out = open.from_output;
    const branch = out === 'true' || out === 'false';
    const accent = out === 'true'
        ? 'var(--sa-color-success)'
        : out === 'false' ? 'var(--sa-color-failed)' : null;
    // Non-branch, non-default outputs (switch cases, loop/parallel handles)
    // still get a label on their stub so an unconnected switch case reads
    // as e.g. "a", not a bare dashed line.
    const showLabel = branch || (out && out !== 'default' && open.label);

    return {
        id: `${STUB_PREFIX}${open.from_node_key}__${out}`,
        source: open.from_node_key,
        sourceHandle: out,
        target: `${ADDER_PREFIX}${open.from_node_key}__${out}`,
        type: 'smoothstep',
        selectable: false,
        deletable: false,
        focusable: false,
        style: { stroke: accent ?? 'var(--color-gray-300, #d1d5db)', strokeDasharray: '4 4' },
        label: branch ? (out === 'true' ? __('If true') : __('If false')) : (showLabel ? open.label : ''),
        labelBgBorderRadius: 8,
        labelBgPadding: showLabel ? [7, 4] : undefined,
        labelStyle: showLabel ? { fill: accent ?? 'var(--color-gray-500, #6b7280)', fontSize: 11, fontWeight: 600 } : undefined,
        labelBgStyle: showLabel
            ? { fill: 'var(--sa-edge-label-bg)', stroke: accent ?? 'var(--color-gray-300, #d1d5db)', strokeWidth: 1 }
            : undefined,
    };
}

function rebuild() {
    const layout = computeLayout(props.nodes, props.edges);
    const nodeByKey = new Map(props.nodes.map((n) => [n.node_key, n]));

    const nodes = props.nodes.map((n) => toVueFlowNode(n, layout.positions[n.node_key]));
    if (!props.nodes.length) {
        nodes.push(rootAdder());
    } else {
        for (const open of layout.openOutputs) {
            const srcPos = layout.positions[open.from_node_key];
            if (srcPos) nodes.push(adderNode(open, srcPos, nodeByKey.get(open.from_node_key)));
        }
    }
    vfNodes.value = nodes;

    const edges = props.edges.map(toVueFlowEdge);
    if (props.nodes.length) {
        for (const open of layout.openOutputs) edges.push(stubEdge(open));
    }
    vfEdges.value = edges;
}

watch([() => props.nodes, () => props.edges], rebuild, { immediate: true, deep: true });

watch(
    () => props.selectedKey,
    (next) => {
        vfNodes.value = vfNodes.value.map((n) => ({ ...n, selected: n.id === next }));
    },
);

// Keep the whole flow framed after structural changes (add / insert / delete).
onNodesInitialized(() => {
    nextTick(() => fitView({ padding: 0.25, duration: 200, maxZoom: 1 }));
});

function statusFor(id) {
    return props.validation[id] || null;
}

function onNodeClick({ node }) {
    if (isSynthetic(node.id)) return;
    emit('select', node.id);
}
</script>

<style>
@import '@vue-flow/core/dist/style.css';
@import '@vue-flow/core/dist/theme-default.css';
@import '@vue-flow/controls/dist/style.css';
@import '@vue-flow/minimap/dist/style.css';
</style>
