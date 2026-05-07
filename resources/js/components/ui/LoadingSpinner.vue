<template>
    <div class="sa-spinner" :class="`sa-spinner--${size}`" role="status" aria-live="polite">
        <span class="sa-spinner__ring"></span>
        <span v-if="label" class="sa-spinner__label">{{ label }}</span>
    </div>
</template>

<script setup>
defineProps({
    label: { type: String, default: null },
    size: { type: String, default: 'medium' }, // small | medium | large
});
</script>
