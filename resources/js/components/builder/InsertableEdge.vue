<template>
    <!-- Custom edge for real connections. Draws the smoothstep path and hangs a
         "+" insert button at its midpoint (Zapier "insert between steps"). For
         branch outputs it also shows the token-coloured "If true"/"If false"
         pill near the split. -->
    <BaseEdge :id="id" :path="path" :style="style" />

    <EdgeLabelRenderer>
        <div
            v-if="data && data.branch"
            class="sa-edge-branch nodrag nopan"
            :class="data.branch === 'true' ? 'sa-edge-branch--true' : 'sa-edge-branch--false'"
            :style="pillTransform"
        >
            {{ data.branch === 'true' ? __('If true') : __('If false') }}
        </div>

        <div class="sa-edge-insert nodrag nopan" :style="insertTransform">
            <NodePicker :library="library" mode="step" @select="onSelect">
                <button type="button" class="sa-edge-insert__btn" :aria-label="__('Insert step')">
                    <Icon name="plus" class="size-3" />
                </button>
            </NodePicker>
        </div>
    </EdgeLabelRenderer>
</template>

<script setup>
import { computed, inject } from 'vue';
import { BaseEdge, EdgeLabelRenderer, getSmoothStepPath, Position } from '@vue-flow/core';
import { Icon } from '@statamic/cms/ui';
import NodePicker from './NodePicker.vue';

const props = defineProps({
    id: { type: String, required: true },
    source: { type: String, required: true },
    target: { type: String, required: true },
    sourceX: { type: Number, required: true },
    sourceY: { type: Number, required: true },
    targetX: { type: Number, required: true },
    targetY: { type: Number, required: true },
    sourcePosition: { type: String, default: Position.Bottom },
    targetPosition: { type: String, default: Position.Top },
    sourceHandleId: { type: String, default: null },
    style: { type: Object, default: () => ({}) },
    data: { type: Object, default: () => ({}) },
});

const library = inject('saLibrary');
const insert = inject('saInsert');

const pathData = computed(() =>
    getSmoothStepPath({
        sourceX: props.sourceX,
        sourceY: props.sourceY,
        sourcePosition: props.sourcePosition,
        targetX: props.targetX,
        targetY: props.targetY,
        targetPosition: props.targetPosition,
    }),
);

const path = computed(() => pathData.value[0]);
const insertTransform = computed(
    () => ({ transform: `translate(-50%, -50%) translate(${pathData.value[1]}px, ${pathData.value[2]}px)` }),
);

// Branch pill sits just below the source handle so it reads as the split label.
const pillTransform = computed(
    () => ({ transform: `translate(-50%, -50%) translate(${props.sourceX}px, ${props.sourceY + 20}px)` }),
);

function onSelect(handle) {
    insert(
        {
            from_node_key: props.source,
            from_output: props.sourceHandleId ?? 'default',
            to_node_key: props.target,
        },
        handle,
    );
}
</script>
