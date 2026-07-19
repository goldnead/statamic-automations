<template>
    <!-- Synthetic "+" node placed under every open output (and, on an empty
         canvas, as the single trigger slot). It is not part of the saved graph;
         Canvas generates it from the layout's open outputs. Clicking no longer
         opens a dropdown — it arms "pick mode" on the left NodeLibrary sidebar;
         the next node clicked there lands at exactly this position. Clicking
         an already-armed "+" again disarms it (see Edit.vue's onTogglePick). -->
    <div class="sa-adder">
        <Handle
            v-if="!isRoot"
            type="target"
            :position="Position.Top"
            class="sa-adder__handle"
            :connectable="false"
        />

        <button
            type="button"
            class="sa-adder__btn"
            :class="{ 'sa-adder__btn--pending': isPending }"
            :aria-label="label"
            :aria-pressed="isPending"
            @click="onClick"
        >
            <Icon name="plus" class="size-4" />
        </button>

        <span v-if="isRoot" class="sa-adder__hint">{{ __('Add a trigger') }}</span>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Icon } from '@statamic/cms/ui';

const props = defineProps({
    data: { type: Object, required: true }, // { fromNodeKey, output, mode }
});

const startPick = inject('saStartPick');
const pendingTarget = inject('saPendingTarget');

const isRoot = computed(() => !props.data.fromNodeKey);
const label = computed(() => (isRoot.value ? __('Add a trigger') : __('Add a step')));

const target = computed(() => ({
    kind: 'append',
    fromNodeKey: props.data.fromNodeKey ?? null,
    output: props.data.output ?? 'default',
}));

const isPending = computed(() => {
    const p = pendingTarget.value;
    if (!p || p.kind !== 'append') return false;
    return p.fromNodeKey === target.value.fromNodeKey && p.output === target.value.output;
});

function onClick() {
    startPick(target.value);
}
</script>
