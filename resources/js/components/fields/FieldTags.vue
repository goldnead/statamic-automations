<template>
    <div class="sa-field sa-field--tags">
        <span v-for="(tag, index) in tags" :key="index" class="sa-field__tag">
            {{ tag }}
            <button type="button" class="sa-field__tag-remove" @click="remove(index)">×</button>
        </span>
        <input
            type="text"
            class="sa-field__input sa-field__input--inline"
            :placeholder="field?.placeholder ?? 'Add tag and press Enter'"
            @keydown.enter.prevent="onEnter($event)"
            @keydown.tab="onEnter($event)"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [Array, null], default: null },
    field: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const tags = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));

function emitTags(next) {
    emit('update:modelValue', next);
}

function add(value) {
    const trimmed = value.trim();
    if (!trimmed) return;
    if (tags.value.includes(trimmed)) return;
    emitTags([...tags.value, trimmed]);
}

function remove(index) {
    const next = [...tags.value];
    next.splice(index, 1);
    emitTags(next);
}

function onEnter(event) {
    add(event.target.value);
    event.target.value = '';
}
</script>
