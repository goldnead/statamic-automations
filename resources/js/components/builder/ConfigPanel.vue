<template>
    <div class="p-4">
        <div v-if="!node" class="text-sm text-gray-500 dark:text-gray-400 text-center py-12">
            {{ __('Select a node to configure it.') }}
        </div>

        <template v-else>
            <header class="mb-3">
                <h3 class="text-base font-semibold m-0">{{ schema?.label ?? node.type }}</h3>
                <p v-if="schema?.description" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ schema.description }}
                </p>
            </header>

            <Field
                v-for="field in fields"
                :key="field.handle"
                :label="field.label"
                :description="field.help"
                class="mb-4"
            >
                <component
                    :is="fieldComponent(field)"
                    v-bind="fieldProps(field)"
                    :model-value="config[field.handle] ?? field.default ?? null"
                    @update:model-value="setField(field.handle, $event)"
                />
            </Field>

            <ConditionBuilder
                v-if="hasConditions"
                :model-value="config.conditions ?? []"
                :mode="config.mode ?? 'all'"
                :trigger-output-schema="triggerOutputSchema"
                @update:model-value="setField('conditions', $event)"
                @update:mode="setField('mode', $event)"
            />
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import {
    Field,
    Input,
    Textarea,
    Select,
    Combobox,
    Switch,
    CodeEditor,
} from '@statamic/cms/ui';
import ConditionBuilder from './ConditionBuilder.vue';

const props = defineProps({
    node: { type: Object, default: null },
    library: { type: Object, required: true },
    triggerOutputSchema: { type: Object, default: null },
    apiBase: { type: String, required: true },
});

const emit = defineEmits(['update:config']);

const schema = computed(() => {
    if (!props.node) return null;
    const all = [
        ...(props.library.triggers ?? []),
        ...(props.library.logic ?? []),
        ...(props.library.actions ?? []),
    ];
    return all.find((m) => m.handle === props.node.type) ?? null;
});

const fields = computed(() => {
    const raw = schema.value?.schema ?? [];
    return raw.filter((f) => !['conditions', 'mode'].includes(f.handle));
});

const hasConditions = computed(() =>
    (schema.value?.schema ?? []).some((f) => f.handle === 'conditions'),
);

const config = computed(() => props.node?.config ?? {});

function setField(handle, value) {
    emit('update:config', { ...config.value, [handle]: value });
}

function fieldComponent(field) {
    return {
        text: Input,
        textarea: Textarea,
        number: Input,
        select: Select,
        combobox: Combobox,
        toggle: Switch,
        code: CodeEditor,
        json: CodeEditor,
        tags: Input,
        key_value: Textarea,
        data_reference: Input,
    }[field.type] ?? Input;
}

function fieldProps(field) {
    const base = {};
    if (field.type === 'number') base.type = 'number';
    if (field.type === 'select') {
        base.options = (field.options ?? []).map((o) =>
            typeof o === 'string' ? { value: o, label: o } : o,
        );
    }
    if (field.type === 'json' || field.type === 'code') {
        base.mode = field.mode ?? 'json';
    }
    if (field.type === 'textarea') {
        base.rows = field.rows ?? 4;
    }
    return base;
}
</script>
