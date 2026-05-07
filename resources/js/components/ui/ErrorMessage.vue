<template>
    <div class="sa-error" :class="`sa-error--${level}`" role="alert">
        <strong v-if="title" class="sa-error__title">{{ title }}</strong>
        <p class="sa-error__message">{{ message }}</p>
        <div v-if="$slots.actions" class="sa-error__actions">
            <slot name="actions" />
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: { type: String, default: null },
    message: { type: String, required: true },
    level: { type: String, default: 'error' }, // error | warning | info
});
</script>
