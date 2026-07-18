<template>
    <!-- Synthetic "+" node placed under every open output (and, on an empty
         canvas, as the single trigger slot). It is not part of the saved graph;
         Canvas generates it from the layout's open outputs. Clicking opens the
         node picker and appends the chosen node at exactly this position. -->
    <div class="sa-adder">
        <Handle
            v-if="!isRoot"
            type="target"
            :position="Position.Top"
            class="sa-adder__handle"
            :connectable="false"
        />

        <NodePicker :library="library" :mode="data.mode" @select="onSelect">
            <button type="button" class="sa-adder__btn" :aria-label="label">
                <Icon name="plus" class="size-4" />
            </button>
        </NodePicker>

        <span v-if="isRoot" class="sa-adder__hint">{{ __('Add a trigger') }}</span>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Icon } from '@statamic/cms/ui';
import NodePicker from './NodePicker.vue';

const props = defineProps({
    data: { type: Object, required: true }, // { fromNodeKey, output, mode }
});

const library = inject('saLibrary');
const append = inject('saAppend');

const isRoot = computed(() => !props.data.fromNodeKey);
const label = computed(() => (isRoot.value ? __('Add a trigger') : __('Add a step')));

function onSelect(handle) {
    append(props.data.fromNodeKey ?? null, props.data.output ?? 'default', handle);
}
</script>
