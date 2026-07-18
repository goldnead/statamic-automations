<template>
    <VueFlow
        :id="flowId"
        v-model:nodes="vfNodes"
        v-model:edges="vfEdges"
        :default-edge-options="defaultEdgeOptions"
        class="size-full"
        @node-click="onNodeClick"
        @edge-click="onEdgeClick"
        @nodes-change="onNodesChange"
        @connect="onConnect"
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

        <Background :pattern-color="dotColor" :gap="18" :size="1.4" />
        <Controls />
        <!-- Hide the MiniMap on an empty canvas so it doesn't render as a bare
             white rectangle; it only carries meaning once there are nodes. -->
        <MiniMap v-if="vfNodes.length" pannable zoomable />

        <Panel position="bottom-left">
            <ControlBar :flow-id="flowId" />
        </Panel>
    </VueFlow>
</template>

<script setup>
import { ref, watch } from 'vue';
import { VueFlow, Panel } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import NodeCard from './NodeCard.vue';
import ControlBar from './ControlBar.vue';

const props = defineProps({
    nodes: { type: Array, required: true },
    edges: { type: Array, required: true },
    selectedKey: { type: String, default: null },
    validation: { type: Object, default: () => ({}) },
    library: { type: Object, default: () => ({ triggers: [], logic: [], actions: [] }) },
});

const emit = defineEmits([
    'select',
    'update-positions',
    'positions-committed',
    'connect',
    'remove-edge',
    'remove-node',
    'rename-node',
    'duplicate-node',
    'toggle-node-disabled',
]);

// Vue Flow paints the dots via an SVG `fill` presentation attribute, which does
// not resolve CSS `var()`. So we pass a neutral literal here and re-tint the
// dots theme-aware from cp.css (`.vue-flow__background circle`), where CSS custom
// properties DO resolve. Keeps the canvas grid on CP gray tokens in both modes.
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


// Scope this Vue Flow instance to a unique id. Vue Flow keeps a module-level
// store keyed by id; a stable per-mount id keeps each builder session isolated
// and lets Vue Flow dispose its store + global listeners cleanly when the CP
// navigates away from the editor (avoids leaking state across route changes).
const flowId = `sa-flow-${Math.random().toString(36).slice(2, 10)}`;

const vfNodes = ref([]);
const vfEdges = ref([]);

const defaultEdgeOptions = {
    type: 'smoothstep',
    animated: false,
};

watch(
    () => props.nodes,
    (next) => { vfNodes.value = next.map(toVueFlowNode); },
    { immediate: true, deep: true },
);

watch(
    () => props.edges,
    (next) => { vfEdges.value = next.map(toVueFlowEdge); },
    { immediate: true, deep: true },
);

watch(
    () => props.selectedKey,
    (next) => {
        vfNodes.value = vfNodes.value.map((n) => ({ ...n, selected: n.id === next }));
    },
);

function nodeKind(type) {
    const inGroup = (group) => (props.library[group] ?? []).some((m) => m.handle === type);
    if (inGroup('triggers')) return 'trigger';
    if (inGroup('logic')) return 'logic';
    if (inGroup('actions')) return 'action';
    return 'action';
}

function toVueFlowNode(n) {
    return {
        id: n.node_key,
        type: nodeKind(n.type),
        position: { x: n.position_x ?? 0, y: n.position_y ?? 0 },
        selected: n.node_key === props.selectedKey,
        data: {
            label: n.label || labelFor(n.type),
            type: n.type,
            config: n.config ?? {},
        },
    };
}

function labelFor(handle) {
    const lib = props.library;
    return [
        ...(lib.triggers ?? []),
        ...(lib.logic ?? []),
        ...(lib.actions ?? []),
    ].find((m) => m.handle === handle)?.label ?? handle;
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
        // Branch outputs get a token-coloured pill label ("If true"/"If false");
        // Vue Flow renders label + labelBg* as a rounded, padded SVG badge.
        label: branch ? (out === 'true' ? __('If true') : __('If false')) : '',
        type: 'smoothstep',
        style: accent ? { stroke: accent } : undefined,
        labelBgBorderRadius: 8,
        labelBgPadding: branch ? [7, 4] : undefined,
        labelStyle: branch ? { fill: accent, fontSize: 11, fontWeight: 600 } : undefined,
        labelBgStyle: branch
            ? { fill: 'var(--sa-edge-label-bg)', stroke: accent, strokeWidth: 1 }
            : undefined,
    };
}

function statusFor(id) {
    return props.validation[id] || null;
}

function onNodeClick({ node }) {
    emit('select', node.id);
}

function onEdgeClick({ edge }) {
    if (window.confirm(window.__ ? __('Delete this connection?') : 'Delete this connection?')) {
        emit('remove-edge', {
            from_node_key: edge.source,
            from_output: edge.sourceHandle ?? 'default',
            to_node_key: edge.target,
        });
    }
}

function onNodesChange(changes) {
    const positions = [];
    // Vue Flow streams position changes throughout a drag; `dragging === false`
    // marks the drop. We apply positions live but only commit ONE history entry
    // per drag (on drop), so undo restores the pre-drag layout atomically.
    let dragEnded = false;
    for (const change of changes) {
        if (change.type === 'position' && change.position) {
            positions.push({
                node_key: change.id,
                position_x: Math.round(change.position.x),
                position_y: Math.round(change.position.y),
            });
            if (change.dragging === false) dragEnded = true;
        }
        if (change.type === 'remove') {
            emit('remove-node', change.id);
        }
    }
    if (positions.length) emit('update-positions', positions);
    if (dragEnded) emit('positions-committed');
}

function onConnect(connection) {
    emit('connect', {
        from_node_key: connection.source,
        from_output: connection.sourceHandle ?? 'default',
        to_node_key: connection.target,
    });
}
</script>

<style>
@import '@vue-flow/core/dist/style.css';
@import '@vue-flow/core/dist/theme-default.css';
@import '@vue-flow/controls/dist/style.css';
@import '@vue-flow/minimap/dist/style.css';
</style>
