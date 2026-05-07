<template>
    <select
        class="sa-field__select"
        :value="modelValue ?? ''"
        @change="$emit('update:modelValue', $event.target.value || null)"
    >
        <option value="">— Select —</option>
        <option v-for="opt in normalizedOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    field: { type: Object, default: () => ({}) },
    options: { type: Array, default: () => [] },
});

defineEmits(['update:modelValue']);

const normalizedOptions = computed(() => {
    if (Array.isArray(props.options) && props.options.length) {
        return props.options.map((opt) => {
            if (typeof opt === 'string') return { value: opt, label: opt };
            return { value: opt.value, label: opt.label ?? opt.value };
        });
    }
    if (Array.isArray(props.field?.options)) {
        return props.field.options.map((opt) =>
            typeof opt === 'string' ? { value: opt, label: opt } : opt,
        );
    }
    return [];
});
</script>
