<template>
    <div class="sa-node sa-node--action" :class="statusClass">
        <div class="sa-node__badge">Action</div>
        <div class="sa-node__title">{{ data.label }}</div>
        <div class="sa-node__type">{{ data.type }}</div>
        <div v-if="summary" class="sa-node__summary">{{ summary }}</div>
        <Handle type="target" :position="Position.Left" />
        <Handle type="source" :position="Position.Right" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Handle, Position } from '@vue-flow/core';

const props = defineProps({
    data: { type: Object, required: true },
    status: { type: String, default: null },
});

const statusClass = computed(() => {
    if (props.status === 'error') return 'sa-node--invalid';
    if (props.status === 'warning') return 'sa-node--incomplete';
    return 'sa-node--valid';
});

const summary = computed(() => {
    const cfg = props.data.config ?? {};
    return cfg.subject || cfg.url || cfg.destination || cfg.message || cfg.tag || cfg.status || '';
});
</script>
