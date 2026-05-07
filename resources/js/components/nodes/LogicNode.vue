<template>
    <div class="sa-node sa-node--logic" :class="statusClass">
        <div class="sa-node__badge">{{ badge }}</div>
        <div class="sa-node__title">{{ data.label }}</div>
        <div class="sa-node__type">{{ data.type }}</div>
        <div v-if="summary" class="sa-node__summary">{{ summary }}</div>

        <Handle type="target" :position="Position.Left" />

        <template v-if="isBranch">
            <Handle id="true" type="source" :position="Position.Right" :style="{ top: '35%' }" />
            <Handle id="false" type="source" :position="Position.Right" :style="{ top: '70%' }" />
            <div class="sa-node__handles">
                <span class="sa-node__handle-label sa-node__handle-label--true">true</span>
                <span class="sa-node__handle-label sa-node__handle-label--false">false</span>
            </div>
        </template>
        <Handle v-else type="source" :position="Position.Right" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Handle, Position } from '@vue-flow/core';

const props = defineProps({
    data: { type: Object, required: true },
    status: { type: String, default: null },
});

const isBranch = computed(() => props.data.type === 'branch');
const badge = computed(() => {
    return {
        filter: 'Filter',
        branch: 'Branch',
        stop: 'Stop',
        delay: 'Delay',
    }[props.data.type] ?? 'Logic';
});

const statusClass = computed(() => {
    if (props.status === 'error') return 'sa-node--invalid';
    if (props.status === 'warning') return 'sa-node--incomplete';
    return 'sa-node--valid';
});

const summary = computed(() => {
    const cfg = props.data.config ?? {};
    if (props.data.type === 'delay') {
        if (cfg.amount && cfg.unit) return `${cfg.amount} ${cfg.unit}`;
        return '';
    }
    if (Array.isArray(cfg.conditions) && cfg.conditions.length) {
        return `${cfg.conditions.length} condition${cfg.conditions.length === 1 ? '' : 's'}`;
    }
    return '';
});
</script>
