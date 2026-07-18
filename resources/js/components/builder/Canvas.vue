<template>
    <VueFlow
        :id="flowId"
        v-model:nodes="vfNodes"
        v-model:edges="vfEdges"
        :nodes-draggable="false"
        :nodes-connectable="false"
        :edges-updatable="false"
        :elements-selectable="true"
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
import { computeLayout, LAYOUT } from '../../composables/useAutoLayout.js';

const props = defineProps({
    nodes: { type: Array, required: true },
    edges: { type: Array, required: true },
    selectedKey: { type: String, default: null },
    validation: { type: Object, default: () => ({}) },
    library: { type: Object, default: () => ({ triggers: [], logic: [], actions: [] }) },
});

const emit = defineEmits([
    'select',
    'append',
    'insert',
    'remove-node',
    'rename-node',
    'duplicate-node',
    'toggle-node-disabled',
]);

// The picker components (adder nodes + insertable edges) are rendered deep
// inside Vue Flow's slot templates. Provide the library and the append/insert
// callbacks so they can reach back up to the page without prop drilling.
provide('saLibrary', props.library);
provide('saAppend', (fromNodeKey, output, handle) =>
    emit('append', { fromNodeKey, output, handle }),
);
provide('saInsert', (edge, handle) => emit('insert', { edge, handle }));

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
    };
}

// Scope this Vue Flow instance to a unique id so each builder session isolates
// its store and disposes cleanly on CP navigation.
const flowId = `sa-flow-${Math.random().toString(36).slice(2, 10)}`;

const vfNodes = ref([]);
const vfEdges = ref([]);
const realNodeCount = computed(() => props.nodes.length);

const { fitView, onNodesInitialized } = useVueFlow(flowId);

// Where each output's handle sits across the node width (see NodeCard: branch
// handles at 32% / 68%, single output centred).
const HANDLE_FRACTION = { default: 0.5, true: 0.32, false: 0.68 };
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

function adderNode(open, srcPos) {
    const frac = HANDLE_FRACTION[open.from_output] ?? 0.5;
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
        sourceHandle: out === 'default' ? null : out,
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

    return {
        id: `${STUB_PREFIX}${open.from_node_key}__${out}`,
        source: open.from_node_key,
        sourceHandle: out === 'default' ? null : out,
        target: `${ADDER_PREFIX}${open.from_node_key}__${out}`,
        type: 'smoothstep',
        selectable: false,
        deletable: false,
        focusable: false,
        style: { stroke: accent ?? 'var(--color-gray-300, #d1d5db)', strokeDasharray: '4 4' },
        label: branch ? (out === 'true' ? __('If true') : __('If false')) : '',
        labelBgBorderRadius: 8,
        labelBgPadding: branch ? [7, 4] : undefined,
        labelStyle: branch ? { fill: accent, fontSize: 11, fontWeight: 600 } : undefined,
        labelBgStyle: branch
            ? { fill: 'var(--sa-edge-label-bg)', stroke: accent, strokeWidth: 1 }
            : undefined,
    };
}

function rebuild() {
    const layout = computeLayout(props.nodes, props.edges);

    const nodes = props.nodes.map((n) => toVueFlowNode(n, layout.positions[n.node_key]));
    if (!props.nodes.length) {
        nodes.push(rootAdder());
    } else {
        for (const open of layout.openOutputs) {
            const srcPos = layout.positions[open.from_node_key];
            if (srcPos) nodes.push(adderNode(open, srcPos));
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
