<template>
    <section class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
        <header class="flex items-center justify-between mb-2">
            <h4 class="text-2xs uppercase tracking-wider text-gray-500 dark:text-gray-400 m-0">
                {{ __('Conditions') }}
            </h4>
            <Select
                :model-value="mode"
                :options="modeOptions"
                class="w-24 text-xs"
                @update:model-value="$emit('update:mode', $event)"
            />
        </header>

        <ul class="space-y-2">
            <li
                v-for="(condition, i) in localValue"
                :key="i"
                class="grid grid-cols-[1fr_120px_1fr_auto] gap-1 items-center"
            >
                <Input
                    :model-value="condition.field"
                    placeholder="lead.email"
                    @update:model-value="updateCondition(i, 'field', $event)"
                />
                <Select
                    :model-value="condition.operator"
                    :options="operatorOptions"
                    @update:model-value="updateCondition(i, 'operator', $event)"
                />
                <Input
                    v-if="needsValue(condition.operator)"
                    :model-value="condition.value ?? ''"
                    @update:model-value="updateCondition(i, 'value', $event)"
                />
                <span v-else class="text-xs text-gray-500 italic">{{ __('no value') }}</span>
                <Button
                    variant="ghost"
                    size="sm"
                    icon="trash"
                    icon-only
                    :text="__('Remove')"
                    @click="remove(i)"
                />
            </li>
        </ul>

        <Button
            variant="ghost"
            size="sm"
            class="mt-2"
            :text="'+ ' + __('Add condition')"
            @click="add"
        />
    </section>
</template>

<script setup>
import { computed } from 'vue';
import { Input, Select, Button } from '@statamic/cms/ui';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    mode: { type: String, default: 'all' },
    triggerOutputSchema: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'update:mode']);

const localValue = computed(() => props.modelValue);

const modeOptions = [
    { value: 'all', label: __('All') },
    { value: 'any', label: __('Any') },
];

const operatorOptions = [
    { value: 'equals', label: 'equals' },
    { value: 'does_not_equal', label: '≠' },
    { value: 'contains', label: 'contains' },
    { value: 'does_not_contain', label: 'not contains' },
    { value: 'is_empty', label: 'is empty' },
    { value: 'is_not_empty', label: 'is not empty' },
    { value: 'greater_than', label: '>' },
    { value: 'less_than', label: '<' },
    { value: 'starts_with', label: 'starts with' },
    { value: 'ends_with', label: 'ends with' },
    { value: 'includes_tag', label: 'includes tag' },
    { value: 'status_is', label: 'status is' },
    { value: 'date_before', label: 'date before' },
    { value: 'date_after', label: 'date after' },
];

function needsValue(op) {
    return !['is_empty', 'is_not_empty'].includes(op);
}

function add() {
    emit('update:modelValue', [
        ...localValue.value,
        { field: '', operator: 'equals', value: '' },
    ]);
}

function remove(i) {
    emit('update:modelValue', localValue.value.filter((_, idx) => idx !== i));
}

function updateCondition(i, key, value) {
    emit(
        'update:modelValue',
        localValue.value.map((c, idx) => (idx === i ? { ...c, [key]: value } : c)),
    );
}
</script>
