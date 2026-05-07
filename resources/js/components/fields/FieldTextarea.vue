<template>
    <div class="sa-field sa-field--textarea">
        <textarea
            class="sa-field__textarea"
            rows="5"
            :value="modelValue ?? ''"
            :placeholder="field?.placeholder ?? ''"
            @input="$emit('update:modelValue', $event.target.value)"
        ></textarea>
        <TokenPicker v-if="dataPickerSource" :source="dataPickerSource" @pick="onPick" />
    </div>
</template>

<script setup>
import TokenPicker from '../TokenPicker.vue';

const props = defineProps({
    modelValue: { type: [String, null], default: null },
    field: { type: Object, default: () => ({}) },
    dataPickerSource: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

function onPick(token) {
    const current = String(props.modelValue ?? '');
    emit('update:modelValue', current + token);
}
</script>
