<template>
    <input
        type="number"
        class="sa-field__input"
        :value="modelValue ?? ''"
        @input="$emit('update:modelValue', toNumber($event.target.value))"
    />
</template>

<script setup>
defineProps({
    modelValue: { type: [Number, String, null], default: null },
    field: { type: Object, default: () => ({}) },
});

defineEmits(['update:modelValue']);

function toNumber(value) {
    if (value === '' || value === null || value === undefined) return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}
</script>
