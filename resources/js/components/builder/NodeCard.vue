<template>
    <div
        class="sa-node"
        :class="[
            `sa-node--${kind}`,
            status === 'error' && 'sa-node--invalid',
            status === 'warning' && 'sa-node--incomplete',
        ]"
    >
        <span class="sa-node__badge">{{ kindLabel }}</span>
        <div class="sa-node__title">{{ data.label }}</div>
        <div class="sa-node__type">{{ data.type }}</div>
        <div v-if="summary" class="sa-node__summary">{{ summary }}</div>
        <Handle v-if="kind !== 'trigger'" type="target" :position="Position.Left" />
        <template v-if="hasBranchOutputs">
            <Handle id="true" type="source" :position="Position.Right" :style="{ top: '40%' }" />
            <Handle id="false" type="source" :position="Position.Right" :style="{ top: '70%' }" />
            <div class="sa-node__handles flex flex-col gap-0.5 mt-1 text-[10px] font-mono">
                <span class="sa-handle-label--true">true →</span>
                <span class="sa-handle-label--false">false →</span>
            </div>
        </template>
        <Handle v-else type="source" :position="Position.Right" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Handle, Position } from '@vue-flow/core';

const props = defineProps({
    kind: { type: String, required: true },
    data: { type: Object, required: true },
    status: { type: String, default: null },
});

const kindLabel = computed(() => ({
    trigger: 'Trigger',
    logic: 'Logic',
    action: 'Action',
}[props.kind] ?? props.kind));

const hasBranchOutputs = computed(() => props.data.type === 'branch');

const summary = computed(() => {
    const config = props.data.config ?? {};
    if (props.data.type === 'send_email' && config.to) return `→ ${config.to}`;
    if (props.data.type === 'send_webhook' && config.url) return `POST ${config.url}`;
    if (props.data.type === 'add_log_entry' && config.message) return config.message.slice(0, 60);
    if (props.data.type === 'filter' && Array.isArray(config.conditions)) {
        return `${config.conditions.length} condition(s)`;
    }
    return null;
});
</script>
