<template>
    <VueFlow
        v-model:nodes="vfNodes"
        v-model:edges="vfEdges"
        :default-edge-options="defaultEdgeOptions"
        class="size-full"
        @node-click="onNodeClick"
        @edge-click="onEdgeClick"
        @nodes-change="onNodesChange"
        @connect="onConnect"
    >
        <template #node-trigger="props">
            <NodeCard kind="trigger" :data="props.data" :status="statusFor(props.id)" />
        </template>
        <template #node-action="props">
            <NodeCard kind="action" :data="props.data" :status="statusFor(props.id)" />
        </template>
        <template #node-logic="props">
            <NodeCard kind="logic" :data="props.data" :status="statusFor(props.id)" />
        </template>

        <Background pattern-color="#aaa" :gap="16" />
        <Controls />
        <MiniMap />
    </VueFlow>
</template>

<script setup>
import { ref, watch } from 'vue';
import { VueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import NodeCard from './NodeCard.vue';

const props = defineProps({
    nodes: { type: Array, required: true },
    edges: { type: Array, required: true },
    selectedKey: { type: String, default: null },
    validation: { type: Object, default: () => ({}) },
    library: { type: Object, default: () => ({ triggers: [], logic: [], actions: [] }) },
});

const emit = defineEmits(['select', 'update-positions', 'connect', 'remove-edge', 'remove-node']);

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
    return {
        id: `${e.from_node_key}__${out}__${e.to_node_key}`,
        source: e.from_node_key,
        target: e.to_node_key,
        sourceHandle: out === 'default' ? null : out,
        label: out === 'default' ? '' : out,
        type: 'smoothstep',
        style: out === 'true' ? { stroke: '#10b981' }
            : out === 'false' ? { stroke: '#ef4444' }
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
    for (const change of changes) {
        if (change.type === 'position' && change.position) {
            positions.push({
                node_key: change.id,
                position_x: Math.round(change.position.x),
                position_y: Math.round(change.position.y),
            });
        }
        if (change.type === 'remove') {
            emit('remove-node', change.id);
        }
    }
    if (positions.length) emit('update-positions', positions);
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
