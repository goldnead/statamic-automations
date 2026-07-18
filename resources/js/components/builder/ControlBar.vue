<template>
    <div class="sa-control-bar">
        <Button
            variant="ghost"
            size="sm"
            inset
            icon-only
            :aria-label="__('Zoom out')"
            @click="zoomOut({ duration: 200 })"
        >
            <span class="text-base leading-none px-1">&minus;</span>
        </Button>

        <span class="sa-control-bar__zoom">{{ zoomPercent }}%</span>

        <Button
            variant="ghost"
            size="sm"
            inset
            icon="plus"
            :aria-label="__('Zoom in')"
            @click="zoomIn({ duration: 200 })"
        />

        <span class="mx-0.5 h-4 w-px bg-gray-200 dark:bg-gray-700" />

        <Button
            variant="ghost"
            size="sm"
            inset
            icon="fit-screen"
            :aria-label="__('Fit to view')"
            @click="fitView({ duration: 300, padding: 0.25, maxZoom: 1 })"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useVueFlow } from '@vue-flow/core';
import { Button } from '@statamic/cms/ui';

const props = defineProps({
    flowId: { type: String, required: true },
});

// Nodes are never draggable in the fixed-layout model, so there is no lock
// toggle — only zoom + fit controls remain.
const { zoomIn, zoomOut, fitView, viewport } = useVueFlow(props.flowId);

const zoomPercent = computed(() => Math.round((viewport.value?.zoom ?? 1) * 100));
</script>
