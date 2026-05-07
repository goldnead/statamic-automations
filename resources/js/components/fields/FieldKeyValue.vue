<template>
    <div class="sa-field sa-field--keyvalue">
        <div v-for="(row, index) in rows" :key="index" class="sa-field__row">
            <input
                type="text"
                class="sa-field__input sa-field__input--small"
                placeholder="Key"
                :value="row.key"
                @input="update(index, 'key', $event.target.value)"
            />
            <input
                type="text"
                class="sa-field__input sa-field__input--small"
                placeholder="Value"
                :value="row.value"
                @input="update(index, 'value', $event.target.value)"
            />
            <button type="button" class="sa-field__remove" @click="remove(index)">×</button>
        </div>
        <button type="button" class="sa-field__add" @click="add">+ Add</button>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [Array, Object, null], default: null },
    field: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const rows = computed(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue.map((r) => ({ key: r.key ?? '', value: r.value ?? '' }));
    }
    if (props.modelValue && typeof props.modelValue === 'object') {
        return Object.entries(props.modelValue).map(([key, value]) => ({ key, value }));
    }
    return [];
});

function emitRows(next) {
    emit('update:modelValue', next);
}

function add() {
    emitRows([...rows.value, { key: '', value: '' }]);
}

function remove(index) {
    const next = [...rows.value];
    next.splice(index, 1);
    emitRows(next);
}

function update(index, key, value) {
    const next = rows.value.map((r, i) => (i === index ? { ...r, [key]: value } : r));
    emitRows(next);
}
</script>
