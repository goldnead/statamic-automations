<script setup>
/**
 * Automation settings — a form, not a printout.
 *
 * Every control on this page is generated from `groups`, which the server built
 * from `Support\Settings`: the same definition the validation and the boot-time
 * config override read. That is deliberate. The read-only version this replaced
 * kept its own list of labels and descriptions in JavaScript, so the screen was
 * a second description of the config file that could disagree with it and never
 * say so.
 *
 * Saving writes only what changed and answers with the settings as they now
 * stand, which is not always what was typed: an empty nullable field comes back
 * null, a number typed into a text box comes back a number, and a value set
 * back to the packaged default comes back as the default with the stored
 * override deleted. The form takes the answer, so the screen and the
 * installation cannot drift.
 */
import { computed, ref, watch } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Button, Card, Panel, Alert, Badge, Field, Input, Textarea, Switch,
} from '@statamic/cms/ui';
import axios from 'axios';

import { firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    title: { type: String, required: true },
    config_path: { type: String, required: true },
    groups: { type: Array, required: true },
    values: { type: Object, required: true },
    updateUrl: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
    integrations: { type: Object, required: true },
});

const integrationMeta = {
    webhook_manager: { label: __('Webhook Manager') },
    leadhub: { label: __('LeadHub') },
};

function humanize(key) {
    return String(key).replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// ---------- The form ----------

/**
 * A `list` is edited as one textarea of lines, because that is how a set of key
 * names is read and pasted. The conversion lives here rather than on the server
 * so the server keeps receiving an array and nothing has to guess at a
 * separator.
 */
function toForm(values) {
    const form = {};

    for (const group of props.groups) {
        for (const field of group.fields) {
            const value = values[field.key];
            form[field.key] = field.type === 'list'
                ? (value ?? []).join('\n')
                : (value ?? (field.type === 'boolean' ? false : ''));
        }
    }

    return form;
}

function fromForm(form) {
    const out = {};

    for (const group of props.groups) {
        for (const field of group.fields) {
            const value = form[field.key];
            out[field.key] = field.type === 'list'
                ? String(value ?? '').split('\n').map((line) => line.trim()).filter(Boolean)
                : value;
        }
    }

    return out;
}

const form = ref(toForm(props.values));
const saved = ref(JSON.stringify(form.value));
const saving = ref(false);
const fieldErrors = ref({});

const dirty = computed(() => JSON.stringify(form.value) !== saved.value);

// An Inertia visit that re-renders this page (a brand switch, a back button)
// hands new props to the same component instance. Without this the form would
// keep showing the values from the visit before it.
watch(() => props.values, (values) => {
    form.value = toForm(values);
    saved.value = JSON.stringify(form.value);
    fieldErrors.value = {};
});

/**
 * Errors that belong to no control on this page. There should be none — every
 * validated key has a field here — but a rule added on the server and not here
 * would otherwise be rejected silently, which is the failure this addon has
 * already shipped twice.
 */
const generalErrors = computed(() => {
    const known = new Set(props.groups.flatMap((g) => g.fields.map((f) => `settings.${f.key}`)));

    return Object.entries(fieldErrors.value)
        .filter(([key]) => ! known.has(key))
        .map(([, messages]) => (Array.isArray(messages) ? messages[0] : messages));
});

function errorFor(field) {
    const messages = fieldErrors.value[`settings.${field.key}`];

    return Array.isArray(messages) ? messages[0] : messages;
}

async function save() {
    if (! props.canEdit || saving.value) return;

    saving.value = true;
    fieldErrors.value = {};

    try {
        const { data } = await axios.patch(props.updateUrl, { settings: fromForm(form.value) });
        form.value = toForm(data.data);
        saved.value = JSON.stringify(form.value);
        window.Statamic?.$toast?.success?.(__('Settings saved.'));
    } catch (e) {
        fieldErrors.value = e?.response?.data?.errors ?? {};
        window.Statamic?.$toast?.error?.(firstMessage(e, __('The settings could not be saved.')));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="sliders-horizontal">
            <Button
                v-if="canEdit"
                variant="primary"
                :text="saving ? __('Saving…') : __('Save')"
                :disabled="saving || ! dirty"
                data-settings-save
                @click="save"
            />
        </Header>

        <Alert v-if="! canEdit" variant="default" class="mb-6">
            {{ __('You can read these settings. Changing them needs the "manage automation settings" permission.') }}
        </Alert>

        <Alert v-if="generalErrors.length" variant="error" class="mb-6" data-settings-form-errors>
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in generalErrors" :key="i">{{ message }}</li>
            </ul>
        </Alert>

        <Alert variant="default" class="mb-6">
            {{ __('These settings apply to the whole installation, not to one brand. Anything not changed here follows :path.', { path: config_path }) }}
        </Alert>

        <div class="space-y-6">
            <Panel
                v-for="group in groups"
                :key="group.title"
                :heading="group.title"
            >
                <Card>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ group.description }}</p>

                    <!-- The marker sits on a wrapper, not on `Field`: Field
                         renders its own root and does not pass stray attributes
                         through, so the hook a test reaches for would not exist
                         in the DOM. -->
                    <div
                        v-for="field in group.fields"
                        :key="field.key"
                        class="mb-5 last:mb-0"
                        :data-settings-field="field.key"
                    >
                        <Field
                            :label="field.label"
                            :instructions="field.description"
                        >
                        <Switch
                            v-if="field.type === 'boolean'"
                            :model-value="form[field.key]"
                            :disabled="! canEdit"
                            @update:model-value="form[field.key] = $event"
                        />
                        <Textarea
                            v-else-if="field.type === 'list'"
                            :model-value="form[field.key]"
                            :rows="8"
                            :disabled="! canEdit"
                            class="font-mono text-sm"
                            @update:model-value="form[field.key] = $event"
                        />
                        <Input
                            v-else
                            :model-value="form[field.key]"
                            :type="field.type === 'integer' ? 'number' : 'text'"
                            :input-attrs="field.min !== undefined ? { min: field.min } : {}"
                            :disabled="! canEdit"
                            :placeholder="field.nullable ? __('Default') : ''"
                            @update:model-value="form[field.key] = $event"
                        />

                        <p
                            v-if="errorFor(field)"
                            class="mt-1 text-sm text-red-600 dark:text-red-400"
                            :data-settings-field-error="field.key"
                        >{{ errorFor(field) }}</p>
                    </Field>
                    </div>
                </Card>
            </Panel>

            <!-- Not a setting: whether a sister addon is installed is decided by
                 composer, so a control here would be a switch that does nothing. -->
            <Panel :heading="__('Integrations')">
                <Card>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Sister addons. Their triggers and actions register automatically when installed — this is what is detected, not something to switch on.') }}
                    </p>

                    <div
                        v-for="(active, key) in integrations"
                        :key="key"
                        class="flex items-center justify-between gap-4 border-t border-gray-200 py-3 first:border-t-0 dark:border-gray-800"
                    >
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ integrationMeta[key]?.label ?? humanize(key) }}
                        </span>
                        <Badge
                            :color="active ? 'green' : 'default'"
                            :text="active ? __('Detected') : __('Not installed')"
                        />
                    </div>
                </Card>
            </Panel>
        </div>
    </div>
</template>
