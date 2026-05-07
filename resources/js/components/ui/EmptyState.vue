<template>
    <div class="sa-empty">
        <div v-if="icon" class="sa-empty__icon" v-html="icon"></div>
        <h3 v-if="title" class="sa-empty__title">{{ title }}</h3>
        <p v-if="message" class="sa-empty__message">{{ message }}</p>
        <div v-if="$slots.actions" class="sa-empty__actions">
            <slot name="actions" />
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: { type: String, default: null },
    message: { type: String, default: null },
    icon: { type: String, default: null },
});
</script>
