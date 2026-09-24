<script setup>
/**
 * One operation of a connection, edited in a stack from the right — the
 * surface core uses to edit an item that belongs to the page behind it.
 *
 * Saves straight to the JSON API (`POST …/operations` or `PATCH …/{id}`) and
 * hands the stored operation back to the page. Validation errors stay in the
 * stack, each at its field; `inputs.N.key` lands on row N of the inputs.
 *
 * Select options travel as a list of `{ value, label }` because that is what
 * the builder's ConfigPanel reads for a `select` field; the key-value editor
 * here works on a map, so they are converted on the way in and out.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import {
    Stack,
    Panel,
    Card,
    Field,
    Input,
    Textarea,
    Select,
    Switch,
    Button,
    Alert,
    Description,
} from '@statamic/cms/ui';

import KeyValueField from '../builder/KeyValueField.vue';
import { errorBag, firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    // The operation as the API presents it, or null for a new one.
    operation: { type: Object, default: null },
    // `…/connections/{id}/operations`; an existing operation appends its id.
    operationsUrl: { type: String, required: true },
    methods: { type: Array, required: true },
    inputTypes: { type: Array, required: true },
});

const emit = defineEmits(['saved', 'update:open']);

const form = ref(blank());
const errors = ref({});
const saving = ref(false);
const handleTouched = ref(false);

const isNew = computed(() => !props.operation?.id);
const title = computed(() => (isNew.value ? __('Add operation') : props.operation.name));

const methodOptions = computed(() => props.methods.map((m) => ({ value: m, label: m })));
const inputTypeLabels = {
    text: __('Text'),
    textarea: __('Textarea'),
    number: __('Number'),
    toggle: __('Toggle'),
    select: __('Select'),
};
const inputTypeOptions = computed(() =>
    props.inputTypes.map((t) => ({ value: t, label: inputTypeLabels[t] ?? t })),
);

function blank() {
    return {
        name: '',
        handle: '',
        description: '',
        method: 'POST',
        path: '',
        query: {},
        body: {},
        inputs: [],
        response_map: {},
        fail_on_error_status: true,
    };
}

let rowSeq = 0;

function fromApi(operation) {
    return {
        ...blank(),
        ...operation,
        description: operation.description ?? '',
        inputs: (operation.inputs ?? []).map((input) => ({
            _key: ++rowSeq,
            handle: input.handle ?? '',
            label: input.label ?? '',
            type: input.type ?? 'text',
            required: Boolean(input.required),
            default: input.default ?? '',
            options: optionsToMap(input.options),
        })),
    };
}

function optionsToMap(options) {
    if (!options) return {};
    if (!Array.isArray(options)) return { ...options };

    return Object.fromEntries(
        options.map((o) => (typeof o === 'string' ? [o, o] : [o.value, o.label ?? o.value])),
    );
}

function payload() {
    const f = form.value;

    return {
        name: f.name,
        handle: f.handle,
        description: f.description || null,
        method: f.method,
        path: f.path,
        query: f.query,
        body: f.body,
        inputs: f.inputs.map((input) => {
            const out = {
                handle: input.handle,
                label: input.label || null,
                type: input.type,
                required: input.required,
                default: input.default === '' ? null : input.default,
            };
            if (input.type === 'select') {
                out.options = Object.entries(input.options ?? {}).map(([value, label]) => ({ value, label }));
            }
            return out;
        }),
        response_map: f.response_map,
        fail_on_error_status: f.fail_on_error_status,
    };
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.value = props.operation ? fromApi(props.operation) : blank();
        errors.value = {};
        handleTouched.value = !isNew.value;
    },
    { immediate: true },
);

// A new operation's handle follows its name until the handle is typed into.
watch(
    () => form.value.name,
    (name) => {
        if (handleTouched.value) return;
        form.value.handle = snake(name);
    },
);

function snake(value) {
    return String(value ?? '')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+/, '')
        .replace(/_+$/, '');
}

function addInput() {
    form.value.inputs.push({
        _key: ++rowSeq,
        handle: '',
        label: '',
        type: 'text',
        required: false,
        default: '',
        options: {},
    });
}

function removeInput(index) {
    form.value.inputs.splice(index, 1);
}

function inputError(index, key) {
    return errors.value[`inputs.${index}.${key}`] ?? null;
}

async function save() {
    saving.value = true;
    try {
        const { data } = isNew.value
            ? await axios.post(props.operationsUrl, payload())
            : await axios.patch(`${props.operationsUrl}/${props.operation.id}`, payload());
        errors.value = {};
        window?.Statamic?.$toast?.success?.(__('Saved'));
        emit('saved', data.data);
        emit('update:open', false);
    } catch (e) {
        errors.value = errorBag(e);
        window?.Statamic?.$toast?.error?.(
            Object.keys(errors.value).length ? __('Something went wrong') : firstMessage(e, __('Something went wrong')),
        );
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Stack
        :open="open"
        :title="title"
        icon="link"
        size="half"
        @update:open="emit('update:open', $event)"
    >
        <div class="space-y-6" data-operation-form>
            <Alert v-if="Object.keys(errors).length" variant="error" data-operation-errors>
                {{ __('The operation could not be saved. The fields below say why.') }}
            </Alert>

            <Panel :heading="__('Operation')">
                <Card class="space-y-6">
                    <div class="grid sm:grid-cols-2 gap-6 *:min-w-0">
                        <Field id="operation_name" :label="__('Name')" required :error="errors.name">
                            <Input id="operation_name" v-model="form.name" />
                        </Field>
                        <Field
                            id="operation_handle"
                            :label="__('Handle')"
                            required
                            :error="errors.handle"
                            instructions-below
                            :instructions="__('Lowercase letters, digits and underscores. Part of the node type.')"
                        >
                            <Input
                                id="operation_handle"
                                v-model="form.handle"
                                class="font-mono"
                                @update:model-value="handleTouched = true"
                            />
                        </Field>
                    </div>
                    <Field id="operation_description" :label="__('Description')" :error="errors.description">
                        <Textarea id="operation_description" v-model="form.description" :rows="2" elastic />
                    </Field>
                </Card>
            </Panel>

            <Panel :heading="__('Request')">
                <Card class="space-y-6">
                    <div class="grid sm:grid-cols-[10rem_1fr] gap-6 *:min-w-0">
                        <Field id="operation_method" :label="__('Method')" required :error="errors.method">
                            <Select id="operation_method" v-model="form.method" :options="methodOptions" />
                        </Field>
                        <Field
                            id="operation_path"
                            :label="__('Path')"
                            required
                            :error="errors.path"
                            :instructions="__('Appended to the base URL. Use {{ input.handle }} for an input.')"
                        >
                            <Input id="operation_path" v-model="form.path" class="font-mono" placeholder="/messages" />
                        </Field>
                    </div>
                    <Field :label="__('Query parameters')" :error="errors.query">
                        <KeyValueField v-model="form.query" :key-label="__('Parameter')" />
                    </Field>
                    <Field
                        :label="__('Body')"
                        :error="errors.body"
                        :instructions="__('Sent as JSON. Leave empty for a request without a body.')"
                    >
                        <KeyValueField v-model="form.body" :key-label="__('Field')" />
                    </Field>
                </Card>
            </Panel>

            <Panel :heading="__('Inputs')" :subheading="__('The fields this action shows on the canvas. Each one takes tokens from earlier steps.')">
                <template #header-actions>
                    <Button size="sm" icon="plus" :text="__('Add input')" data-operation-add-input @click="addInput" />
                </template>
                <Card v-if="form.inputs.length === 0">
                    <Description :text="__('No inputs yet. An operation without inputs sends the same request every time.')" />
                </Card>
                <Card
                    v-for="(input, index) in form.inputs"
                    :key="input._key"
                    class="space-y-6"
                    :class="{ 'mt-2': index > 0 }"
                    :data-operation-input="index"
                >
                    <div class="grid sm:grid-cols-3 gap-6 *:min-w-0">
                        <Field :id="`input_${input._key}_handle`" :label="__('Handle')" required :error="inputError(index, 'handle')">
                            <Input :id="`input_${input._key}_handle`" v-model="input.handle" class="font-mono" />
                        </Field>
                        <Field :id="`input_${input._key}_label`" :label="__('Label')" :error="inputError(index, 'label')">
                            <Input :id="`input_${input._key}_label`" v-model="input.label" />
                        </Field>
                        <Field :id="`input_${input._key}_type`" :label="__('Type')" required :error="inputError(index, 'type')">
                            <Select :id="`input_${input._key}_type`" v-model="input.type" :options="inputTypeOptions" />
                        </Field>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-6 *:min-w-0">
                        <Field :id="`input_${input._key}_default`" :label="__('Default')" :error="inputError(index, 'default')">
                            <Input :id="`input_${input._key}_default`" v-model="input.default" />
                        </Field>
                        <Field :id="`input_${input._key}_required`" :label="__('Required')" :error="inputError(index, 'required')">
                            <Switch :id="`input_${input._key}_required`" v-model="input.required" />
                        </Field>
                        <div class="flex items-end justify-end">
                            <Button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                :text="__('Remove input')"
                                @click="removeInput(index)"
                            />
                        </div>
                    </div>
                    <Field
                        v-if="input.type === 'select'"
                        :label="__('Options')"
                        :error="inputError(index, 'options')"
                    >
                        <KeyValueField v-model="input.options" :key-label="__('Value')" :value-label="__('Label')" />
                    </Field>
                </Card>
            </Panel>

            <Panel :heading="__('Response')">
                <Card class="space-y-6">
                    <Field
                        :label="__('Output fields')"
                        :error="errors.response_map"
                        :instructions="__('Name an output and the path in the JSON response it reads, e.g. message.ts. Later steps use it as a token.')"
                    >
                        <KeyValueField v-model="form.response_map" :key-label="__('Output')" :value-label="__('Path in response')" />
                    </Field>
                    <Field
                        id="operation_fail_on_error_status"
                        :label="__('Fail on an error status')"
                        :error="errors.fail_on_error_status"
                        :instructions="__('Marks the step as failed when the service answers with a status outside 2xx.')"
                    >
                        <Switch id="operation_fail_on_error_status" v-model="form.fail_on_error_status" />
                    </Field>
                </Card>
            </Panel>
        </div>

        <template #footer-end>
            <Button variant="ghost" :text="__('Cancel')" @click="emit('update:open', false)" />
            <Button
                variant="primary"
                :text="isNew ? __('Add operation') : __('Save')"
                :loading="saving"
                data-operation-save
                @click="save"
            />
        </template>
    </Stack>
</template>
