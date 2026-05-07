<template>
    <div class="sa-canvas">
        <VueFlow
            v-model:nodes="vfNodes"
            v-model:edges="vfEdges"
            :default-edge-options="defaultEdgeOptions"
            class="sa-canvas__flow"
            @node-click="onNodeClick"
            @edge-click="onEdgeClick"
            @nodes-change="onNodesChange"
            @connect="onConnect"
        >
            <template #node-trigger="props">
                <TriggerNode v-bind="props" :status="statusFor(props.id)" />
            </template>
            <template #node-action="props">
                <ActionNode v-bind="props" :status="statusFor(props.id)" />
            </template>
            <template #node-logic="props">
                <LogicNode v-bind="props" :status="statusFor(props.id)" />
            </template>

            <Background pattern-color="#aaa" :gap="16" />
            <Controls />
            <MiniMap />
        </VueFlow>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { VueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import TriggerNode from './nodes/TriggerNode.vue';
import ActionNode from './nodes/ActionNode.vue';
import LogicNode from './nodes/LogicNode.vue';

const props = defineProps({
    nodes: { type: Array, required: true },
    edges: { type: Array, required: true },
    selectedKey: { type: String, default: null },
    validation: { type: Object, default: () => ({}) },
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
    (next) => {
        vfNodes.value = next.map((n) => toVueFlowNode(n));
    },
    { immediate: true, deep: true },
);

watch(
    () => props.edges,
    (next) => {
        vfEdges.value = next.map((e) => toVueFlowEdge(e));
    },
    { immediate: true, deep: true },
);

watch(
    () => props.selectedKey,
    (next) => {
        vfNodes.value = vfNodes.value.map((n) => ({
            ...n,
            selected: n.id === next,
        }));
    },
);

function toVueFlowNode(n) {
    const kind = nodeKind(n.type);
    return {
        id: n.node_key,
        type: kind,
        position: { x: n.position_x ?? 0, y: n.position_y ?? 0 },
        selected: n.node_key === props.selectedKey,
        data: {
            label: n.label || n.type,
            type: n.type,
            config: n.config ?? {},
        },
    };
}

function toVueFlowEdge(e) {
    return {
        id: edgeId(e),
        source: e.from_node_key,
        target: e.to_node_key,
        sourceHandle: e.from_output === 'default' ? null : e.from_output,
        label: e.from_output === 'default' ? '' : e.from_output,
        type: 'smoothstep',
        animated: false,
        style: e.from_output === 'true'
            ? { stroke: '#16a34a' }
            : e.from_output === 'false'
                ? { stroke: '#dc2626' }
                : undefined,
    };
}

function edgeId(e) {
    return `${e.from_node_key}__${e.from_output ?? 'default'}__${e.to_node_key}`;
}

function nodeKind(type) {
    if (props.validation && false) return 'logic'; // placeholder for future per-type lookup
    if (type.includes('trigger')
        || type === 'manual'
        || type === 'form_submitted'
        || type === 'entry_published'
        || type.startsWith('leadhub.lead_')
        || type.startsWith('webhook_manager.outbound_')
        || type === 'webhook_manager.inbound_received') {
        return 'trigger';
    }
    if (['filter', 'branch', 'stop', 'delay'].includes(type)) {
        return 'logic';
    }
    return 'action';
}

function statusFor(id) {
    return props.validation[id] || null;
}

function onNodeClick({ node }) {
    emit('select', node.id);
}

function onEdgeClick({ edge }) {
    if (window.confirm('Delete this connection?')) {
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
    if (positions.length) {
        emit('update-positions', positions);
    }
}

function onConnect(connection) {
    emit('connect', {
        from_node_key: connection.source,
        from_output: connection.sourceHandle ?? 'default',
        to_node_key: connection.target,
    });
}
</script>
