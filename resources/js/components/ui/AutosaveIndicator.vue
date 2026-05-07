<template>
    <span class="sa-autosave" :class="`sa-autosave--${status}`" :title="title">
        <span class="sa-autosave__dot"></span>
        <span class="sa-autosave__label">{{ label }}</span>
    </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: 'idle' },
    lastSavedAt: { type: Date, default: null },
    lastError: { type: String, default: null },
});

const label = computed(() => {
    switch (props.status) {
        case 'pending': return 'Saving…';
        case 'saving': return 'Saving…';
        case 'saved': return props.lastSavedAt
            ? `Saved at ${props.lastSavedAt.toLocaleTimeString()}`
            : 'Saved';
        case 'error': return 'Autosave failed';
        default: return 'Autosave off';
    }
});

const title = computed(() => {
    if (props.status === 'error' && props.lastError) {
        return `Autosave failed: ${props.lastError}`;
    }
    return label.value;
});
</script>
