<template>
    <div class="sa-conditions">
        <div class="sa-conditions__mode">
            <label>
                <input type="radio" :value="'all'" :checked="mode === 'all'" @change="emitMode('all')" />
                <span>All conditions must match</span>
            </label>
            <label>
                <input type="radio" :value="'any'" :checked="mode === 'any'" @change="emitMode('any')" />
                <span>Any condition matches</span>
            </label>
        </div>

        <ul class="sa-conditions__list">
            <li v-for="(condition, index) in items" :key="index" class="sa-conditions__row">
                <input
                    type="text"
                    class="sa-conditions__field"
                    placeholder="lead.status"
                    :value="condition.field"
                    @input="updateRow(index, 'field', $event.target.value)"
                />

                <select
                    class="sa-conditions__operator"
                    :value="condition.operator || 'equals'"
                    @change="updateRow(index, 'operator', $event.target.value)"
                >
                    <option v-for="op in operators" :key="op" :value="op">{{ op }}</option>
                </select>

                <input
                    v-if="needsValue(condition.operator)"
                    type="text"
                    class="sa-conditions__value"
                    placeholder="Value (or {{ token }})"
                    :value="condition.value"
                    @input="updateRow(index, 'value', $event.target.value)"
                />
                <span v-else class="sa-conditions__no-value">no value</span>

                <button type="button" class="sa-conditions__remove" @click="removeRow(index)">×</button>
            </li>
        </ul>

        <button type="button" class="sa-conditions__add" @click="addRow">+ Add condition</button>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [Array, Object, null], default: null },
});

const emit = defineEmits(['update:modelValue']);

const operators = [
    'equals',
    'does_not_equal',
    'contains',
    'does_not_contain',
    'starts_with',
    'ends_with',
    'is_empty',
    'is_not_empty',
    'greater_than',
    'less_than',
    'greater_than_or_equal',
    'less_than_or_equal',
    'date_before',
    'date_after',
    'includes_tag',
    'status_is',
];

const items = computed(() => {
    return Array.isArray(props.modelValue) ? props.modelValue : [];
});

const mode = computed(() => 'all');

function needsValue(op) {
    return !['is_empty', 'is_not_empty'].includes(op);
}

function update(items) {
    emit('update:modelValue', items);
}

function addRow() {
    update([...items.value, { field: '', operator: 'equals', value: '' }]);
}

function removeRow(index) {
    const next = [...items.value];
    next.splice(index, 1);
    update(next);
}

function updateRow(index, key, value) {
    const next = items.value.map((row, i) => (i === index ? { ...row, [key]: value } : row));
    update(next);
}

function emitMode(_value) {
    // Mode is handled at the node level via the separate `mode` field
    // declared in the node schema; this radio is purely informative for
    // now. A future iteration can wire it up properly.
}
</script>
