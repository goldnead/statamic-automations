<template>
    <div class="sa-field sa-field--reference">
        <input
            type="text"
            class="sa-field__input"
            :value="modelValue ?? defaultToken"
            :placeholder="defaultToken"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <p class="sa-field__hint">
            Defaults to <code>{{ defaultToken }}</code> when left empty.
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [String, null], default: null },
    field: { type: Object, default: () => ({}) },
});

defineEmits(['update:modelValue']);

const defaultToken = computed(() => {
    const source = props.field?.source ?? 'lead';
    return `{{ ${source}.id }}`;
});
</script>
