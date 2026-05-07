<template>
    <aside class="sa-config">
        <div v-if="!node" class="sa-config__empty">
            Select a node to edit its configuration.
        </div>

        <div v-else class="sa-config__inner">
            <header class="sa-config__header">
                <h3 class="sa-config__title">{{ schema?.label ?? node.type }}</h3>
                <p v-if="schema?.description" class="sa-config__description">{{ schema.description }}</p>
            </header>

            <div class="sa-config__field">
                <label class="sa-config__label">Node key</label>
                <input
                    type="text"
                    class="sa-config__input"
                    :value="node.node_key"
                    @change="renameNode($event)"
                />
                <p class="sa-config__help">Used as a stable reference in tokens (e.g. {{ '{{' }} nodes.{{ node.node_key }}.field {{ '}}' }}).</p>
            </div>

            <div v-for="field in schema?.schema ?? []" :key="field.handle" class="sa-config__field">
                <label class="sa-config__label">
                    {{ field.label }}
                    <span v-if="field.required" class="sa-config__required">*</span>
                </label>

                <component
                    :is="fieldComponent(field)"
                    :model-value="modelFor(field)"
                    :field="field"
                    :options="optionsFor(field)"
                    :data-picker-source="dataPickerSource"
                    @update:model-value="updateField(field, $event)"
                />

                <p v-if="field.help" class="sa-config__help">{{ field.help }}</p>
            </div>
        </div>
    </aside>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../api/client.js';
import TokenPicker from './TokenPicker.vue';
import ConditionBuilder from './ConditionBuilder.vue';

import FieldText from './fields/FieldText.vue';
import FieldTextarea from './fields/FieldTextarea.vue';
import FieldNumber from './fields/FieldNumber.vue';
import FieldSelect from './fields/FieldSelect.vue';
import FieldKeyValue from './fields/FieldKeyValue.vue';
import FieldTags from './fields/FieldTags.vue';
import FieldDataReference from './fields/FieldDataReference.vue';

const props = defineProps({
    node: { type: Object, default: null },
    schema: { type: Object, default: null },
    dataPickerSource: { type: String, default: null },
});

const emit = defineEmits(['update-config', 'rename']);

const dynamicOptions = ref({});

const fields = computed(() => props.schema?.schema ?? []);

watch(fields, async (newFields) => {
    for (const field of newFields) {
        if (field.options_source && !dynamicOptions.value[field.options_source]) {
            try {
                const data = await api.nodes.options(field.options_source);
                dynamicOptions.value = {
                    ...dynamicOptions.value,
                    [field.options_source]: data,
                };
            } catch {
                dynamicOptions.value = {
                    ...dynamicOptions.value,
                    [field.options_source]: [],
                };
            }
        }
    }
}, { immediate: true, deep: true });

function fieldComponent(field) {
    return {
        text: FieldText,
        textarea: FieldTextarea,
        number: FieldNumber,
        select: FieldSelect,
        key_value: FieldKeyValue,
        tags: FieldTags,
        data_reference: FieldDataReference,
        condition_list: ConditionBuilder,
    }[field.type] ?? FieldText;
}

function modelFor(field) {
    return props.node?.config?.[field.handle] ?? null;
}

function optionsFor(field) {
    if (field.options_source) {
        return dynamicOptions.value[field.options_source] ?? [];
    }
    if (Array.isArray(field.options)) {
        return field.options.map((opt) => {
            if (typeof opt === 'string') return { value: opt, label: opt };
            return opt;
        });
    }
    return [];
}

function updateField(field, value) {
    if (!props.node) return;
    emit('update-config', {
        node_key: props.node.node_key,
        config: { [field.handle]: value },
    });
}

function renameNode(event) {
    const newKey = event.target.value.trim();
    if (!newKey || newKey === props.node.node_key) return;
    emit('rename', { old_key: props.node.node_key, new_key: newKey });
}
</script>
