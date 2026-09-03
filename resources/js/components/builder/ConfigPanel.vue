<template>
    <div class="flex flex-col h-full">
        <!-- The panel only ever mounts while a node is selected (see Edit.vue's
             `v-if="selectedNode"`), so there is no inline empty state here —
             deselecting collapses the whole column instead. -->
        <template v-if="node">
            <!-- Card header: icon chip + node title + close (deselect) -->
            <header
                v-if="showHeader"
                class="flex items-center gap-2.5 px-4 py-3 border-b border-gray-200 dark:border-gray-800"
            >
                <span class="sa-icon-chip size-8" :class="`sa-icon-chip--${kind}`">
                    <Icon :name="icon" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold m-0 truncate">{{ node.label || schema?.label || node.type }}</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 font-mono truncate m-0">{{ node.type }}</p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    icon-only
                    icon="x"
                    :aria-label="__('Close')"
                    class="shrink-0"
                    @click="$emit('deselect')"
                />
            </header>

            <div class="flex-1 overflow-y-auto">
                <!-- 1 · Detail information (editable name; node has no description field) -->
                <PropertiesSection :title="__('Detail information')">
                    <Field :label="__('Name')">
                        <Input
                            :model-value="node.label ?? ''"
                            :placeholder="schema?.label || node.type"
                            @update:model-value="$emit('update:label', $event)"
                        />
                    </Field>
                    <p v-if="schema?.description" class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        {{ schema.description }}
                    </p>
                </PropertiesSection>

                <!-- 2 · Configuration (the existing dynamic form — logic unchanged) -->
                <PropertiesSection v-if="fields.length || hasConditions" :title="__('Configuration')">
                    <!-- Keyed by node AND handle, not by handle alone. The
                         panel component is reused across node selections (no
                         :key on it in Edit.vue), so a bare handle key let Vue
                         reuse the field's child component too — and a child
                         with internal state, KeyValueField above all, carried
                         the previous node's half-typed rows over to the next
                         node and committed them there on the first keystroke.
                         Including the node key remounts the control instead. -->
                    <Field
                        v-for="field in fields"
                        :key="`${node.node_key}::${field.handle}`"
                        :label="field.label"
                        :instructions="field.help"
                        class="mb-4"
                        :class="fieldErrors[field.handle] && 'sa-field--invalid'"
                    >
                        <template v-if="isTokenable(field)" #actions>
                            <TokenInserter
                                :variables="nodeVariables"
                                :model-value="config[field.handle] ?? field.default ?? ''"
                                :target-id="fieldDomId(field)"
                                :mode="tokenMode(field)"
                                @update:model-value="setField(field.handle, $event)"
                            />
                        </template>

                        <component
                            :is="OptionsSelect"
                            v-if="field.options_source"
                            :source="field.options_source"
                            :params="dependsOnParams(field)"
                            :api-base="apiBase"
                            :placeholder="field.placeholder ?? null"
                            :model-value="config[field.handle] ?? field.default ?? null"
                            @update:model-value="setField(field.handle, $event)"
                        />
                        <component
                            :is="fieldComponent(field)"
                            v-else
                            v-bind="fieldProps(field)"
                            :model-value="config[field.handle] ?? field.default ?? null"
                            @update:model-value="setField(field.handle, $event)"
                        />

                        <!-- Email template affordances: rendered preview +
                             master-detail picker, next to the template select. -->
                        <div
                            v-if="isEmailTemplateField(field)"
                            class="mt-2 flex items-center gap-2"
                        >
                            <Button
                                size="xs"
                                variant="filled"
                                icon="eye"
                                :text="__('Vorschau')"
                                :disabled="!config[field.handle]"
                                @click="openEmailPreview(field)"
                            />
                            <Button
                                size="xs"
                                variant="ghost"
                                icon="layout-list"
                                :text="__('Vorlage wählen')"
                                @click="openEmailPicker(field)"
                            />
                        </div>

                        <p v-if="fieldErrors[field.handle]" class="sa-field-error">
                            {{ fieldErrors[field.handle] }}
                        </p>
                    </Field>

                    <ConditionBuilder
                        v-if="hasConditions"
                        :model-value="config.conditions ?? []"
                        :mode="config.mode ?? 'all'"
                        :trigger-output-schema="triggerOutputSchema"
                        @update:model-value="setField('conditions', $event)"
                        @update:mode="setField('mode', $event)"
                    />
                </PropertiesSection>

                <!-- 3 · Properties (read-only; only fields the backend actually provides) -->
                <PropertiesSection :title="__('Properties')" :default-open="false">
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-xs">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Type') }}</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-300 text-right truncate">{{ node.type }}</dd>

                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Category') }}</dt>
                        <dd class="text-right">
                            <Badge :color="kindColor" :text="kindLabel" size="sm" pill />
                        </dd>

                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Node key') }}</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-300 text-right truncate">{{ node.node_key }}</dd>

                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
                        <dd class="text-right">
                            <Badge
                                :color="node.disabled ? 'default' : 'emerald'"
                                :text="node.disabled ? __('Disabled') : __('Active')"
                                size="sm"
                                pill
                            />
                        </dd>
                    </dl>
                </PropertiesSection>
            </div>

            <!-- Sticky footer actions. Rendered only for non-trigger nodes:
                 a trigger has neither action (one-trigger-per-flow, see below),
                 so for a trigger the whole bar is an empty strip with a border
                 on top — a divider under nothing. -->
            <footer
                v-if="kind !== 'trigger'"
                class="flex items-center gap-2 px-4 py-3 border-t border-gray-200 dark:border-gray-800 bg-content-bg"
            >
                <!-- Trigger nodes have no Duplicate here either (same
                     one-trigger-per-flow rule as Delete below): duplicating
                     a trigger would create a second one, which has no
                     Delete action to recover from. -->
                <Button
                    :text="__('Duplicate')"
                    icon="duplicate"
                    variant="ghost"
                    size="sm"
                    @click="$emit('duplicate')"
                />
                <!-- Delete is a `DropdownItem variant="destructive"`, not a
                     `Button variant="danger"`. Core reserves `danger` — a solid
                     red fill — for the confirm button inside a modal; a
                     destructive action anywhere else lives behind the `…` menu
                     (ui-vocabulary §24). The Dropdown renders its own dots
                     trigger, so there is no `#trigger` here.

                     Trigger nodes have no Delete (one-trigger-per-flow — see
                     NodeCard.vue's dropdown / Edit.vue's removeNode);
                     "Replace trigger" in the node's "..." menu is the only
                     way to change it. -->
                <Dropdown align="end" class="ml-auto">
                    <DropdownMenu>
                        <DropdownItem
                            :text="__('Delete node')"
                            icon="trash"
                            variant="destructive"
                            @click="$emit('delete')"
                        />
                    </DropdownMenu>
                </Dropdown>
            </footer>

            <!-- Email template preview + picker (mounted once; driven by the
                 field buttons above via emailFieldHandle). -->
            <EmailPreviewModal
                v-model:open="emailPreviewOpen"
                :slug="emailSlug"
                :api-base="apiBase"
            />
            <EmailTemplatePicker
                v-model:open="emailPickerOpen"
                :model-value="emailSlug"
                :api-base="apiBase"
                @select="onEmailTemplateSelected"
            />
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, ref, watch } from 'vue';
import {
    Badge,
    Button,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    Field,
    Input,
    Textarea,
    Select,
    Combobox,
    Switch,
    CodeEditor,
    Icon,
} from '@statamic/cms/ui';
import ConditionBuilder from './ConditionBuilder.vue';
import EmailPreviewModal from './EmailPreviewModal.vue';
import EmailTemplatePicker from './EmailTemplatePicker.vue';
import KeyValueField from './KeyValueField.vue';
import PropertiesSection from './PropertiesSection.vue';
import TokenInserter from './TokenInserter.vue';
import { nodeIcon } from '../../composables/useNodeIcon.js';
import { useNodeOptions } from '../../composables/useNodeOptions.js';
import { useNodeVariables } from '../../composables/useNodeVariables.js';

/**
 * Async, cascading `<select>` for fields declaring `options_source`.
 * Wraps `@statamic/cms/ui` Select + `useNodeOptions`, defined inline here
 * (rather than as a separate SFC) since it's only ever used from the field
 * loop below. Shows a loading placeholder while fetching and a hint in the
 * dropdown's empty state instead of a blank list.
 */
const OptionsSelect = defineComponent({
    name: 'OptionsSelect',
    props: {
        modelValue: { type: [String, Number, null], default: null },
        source: { type: String, required: true },
        params: { type: Object, default: () => ({}) },
        apiBase: { type: String, required: true },
        placeholder: { type: String, default: null },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const { options, loading, error } = useNodeOptions(
            computed(() => props.source),
            computed(() => props.params),
            props.apiBase,
        );

        const emptyHint = computed(() => {
            if (loading.value) return __('Loading…');
            if (error.value) return __('Could not load options.');
            if (/webhook_manager|^webhooks$/.test(props.source)) {
                return __('Install Webhook Manager to use this option.');
            }
            return __('No options');
        });

        return () =>
            h(
                Select,
                {
                    modelValue: props.modelValue,
                    'onUpdate:modelValue': (value) => emit('update:modelValue', value),
                    options: options.value,
                    disabled: loading.value,
                    placeholder: loading.value ? __('Loading…') : (props.placeholder ?? __('Select an option')),
                },
                {
                    'no-options': () =>
                        h(
                            'div',
                            { class: 'text-xs text-gray-500 dark:text-gray-400 px-2 py-1.5' },
                            emptyHint.value,
                        ),
                },
            );
    },
});

const props = defineProps({
    node: { type: Object, default: null },
    library: { type: Object, required: true },
    triggerOutputSchema: { type: Object, default: null },
    apiBase: { type: String, required: true },
    // handle → error message for invalid fields on the selected node (A3).
    // Drives the red field ring + inline message below each control.
    fieldErrors: { type: Object, default: () => ({}) },
    // Full graph — `{ nodes: [...], edges: [...] }` — used by
    // `useNodeVariables` to walk upstream from the selected node and
    // collect the variables `TokenInserter` offers on `tokenable` fields.
    automation: { type: Object, default: () => ({ nodes: [], edges: [] }) },
    // Most recent (test) run for this automation — `{ node_runs: [...] }` from
    // `AutomationsController::test`. Optional; when present, `useNodeVariables`
    // resolves each token's SAMPLE VALUE from the recorded node outputs so the
    // picker can show `entry.title → "My Post"`. Absent ⇒ no samples, nothing
    // else changes.
    lastRun: { type: Object, default: null },
    // The panel names the node it edits — except inside a Stack, where the
    // stack's own header already does, and two titles above one form read as a
    // mistake. Only the header is dropped; the form below is the same in both
    // places, deliberately, so a mail edited from the list and the same mail
    // edited on the canvas are the same act.
    showHeader: { type: Boolean, default: true },
});

const emit = defineEmits(['update:config', 'update:label', 'delete', 'duplicate', 'deselect']);

const schema = computed(() => {
    if (!props.node) return null;
    const all = [
        ...(props.library.triggers ?? []),
        ...(props.library.logic ?? []),
        ...(props.library.actions ?? []),
    ];
    return all.find((m) => m.handle === props.node.type) ?? null;
});

const kind = computed(() => {
    if (!props.node) return 'action';
    const inGroup = (group) => (props.library[group] ?? []).some((m) => m.handle === props.node.type);
    if (inGroup('triggers')) return 'trigger';
    if (inGroup('logic')) return 'logic';
    return 'action';
});

const kindLabel = computed(() => ({
    trigger: __('Trigger'),
    logic: __('Logic'),
    action: __('Action'),
}[kind.value] ?? kind.value));

const kindColor = computed(() => ({
    trigger: 'blue',
    logic: 'amber',
    action: 'emerald',
}[kind.value] ?? 'default'));

const icon = computed(() => nodeIcon(props.node?.type, kind.value));

const hasConditions = computed(() =>
    (schema.value?.schema ?? []).some((f) => f.handle === 'conditions'),
);

const fields = computed(() => {
    const raw = schema.value?.schema ?? [];

    // `conditions` is always rendered by ConditionBuilder instead of the
    // generic field loop, so it is filtered out unconditionally.
    //
    // `mode` belongs to ConditionBuilder too — but only where there ARE
    // conditions for it to combine (filter, branch, wait_until: all/any).
    // Filtering it unconditionally removed the field from four node types
    // that declare a `mode` and no `conditions`, and nothing rendered a
    // replacement: parallel and loop lost their inline/automation switch, and
    // `add_user_to_group` / `assign_user_role` lost the add/remove switch
    // entirely — so "remove from group" and "remove role" could not be
    // configured at all. The default seeded by `defaultConfigForSchema` kept
    // the node valid, which is why nothing ever complained.
    const ownedByConditionBuilder = hasConditions.value ? ['conditions', 'mode'] : ['conditions'];

    return raw.filter((f) => !ownedByConditionBuilder.includes(f.handle));
});

const config = computed(() => props.node?.config ?? {});

// Upstream token variables for the selected node (Task 2.3). Computed once
// per node selection and shared by every `TokenInserter` on this panel —
// the field loop below only needs to know *which* fields are tokenable.
const nodeVariables = useNodeVariables(
    () => props.node?.node_key ?? null,
    () => props.automation,
    () => props.library,
    () => props.lastRun,
);

// Which field types can carry a token, and HOW the token is applied:
//  - `insert` types render a native input/textarea with a caret, so a token
//    is spliced in at the cursor (`"Hallo {{ entry.title }}"`).
//  - `replace` types (select/combobox) accept a token AS their whole value —
//    there's no caret, so picking a token sets the field outright.
// `key_value` is deliberately absent here: it's a multi-row editor whose
// *value* cells each get their own picker inside KeyValueField, not a single
// field-level one.
const TOKEN_INSERT_TYPES = ['text', 'textarea', 'data_reference'];
const TOKEN_REPLACE_TYPES = ['select', 'combobox'];

// Types that default to tokenable WITHOUT needing an explicit flag — free
// text has always offered the picker, so keep that implicit. Everything else
// (select/combobox/…) must opt in with `tokenable: true`, because for an enum
// `select` a token is usually NOT a valid value.
const TOKENABLE_BY_DEFAULT = ['text', 'textarea'];

/**
 * Declarative tokenability: a field opts in/out with `tokenable`, with a
 * sensible per-type default when the flag is absent.
 *  - `tokenable: false` → never (hard opt-out, even for text).
 *  - `tokenable: true`  → yes, for any insert/replace-capable type.
 *  - unset              → default: text/textarea yes, everything else no.
 * Fields backed by a resolved options endpoint (`options_source`, i.e. a real
 * enum picker) never get the picker — a raw token isn't one of their options.
 */
function isTokenable(field) {
    if (field.options_source) return false;
    const supported = TOKEN_INSERT_TYPES.includes(field.type) || TOKEN_REPLACE_TYPES.includes(field.type);
    if (!supported) return false;
    if (field.tokenable === false) return false;
    if (field.tokenable === true) return true;
    return TOKENABLE_BY_DEFAULT.includes(field.type);
}

function tokenMode(field) {
    return TOKEN_REPLACE_TYPES.includes(field.type) ? 'replace' : 'insert';
}

// Stable per-node-per-field DOM id so `TokenInserter` can look up the
// native input/textarea element (via `document.getElementById`) to read
// and set caret position — see `TokenInserter.vue` for why `id` rather
// than a template ref.
function fieldDomId(field) {
    return `sa-field-${props.node?.node_key ?? 'none'}-${field.handle}`;
}

function setField(handle, value) {
    emit('update:config', { ...config.value, [handle]: value });
}

/**
 * Resolves the query params to send with an `options_source` fetch.
 * A field declaring `depends_on: '<otherFieldHandle>'` passes that other
 * field's current config value as a param named after the target handle
 * (e.g. `entry` field with `depends_on: 'collection'` → `{ collection }`,
 * matching `NodesController::options()`'s `$request->query('collection')`).
 * Returns `{}` (no params, i.e. unscoped fetch) until the dependency has a
 * value, so cascading pickers stay empty/hinted rather than fetching an
 * unscoped list.
 */
function dependsOnParams(field) {
    if (!field.depends_on) return {};
    const value = config.value[field.depends_on];
    if (value === null || value === undefined || value === '') return {};
    return { [field.depends_on]: value };
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
        key_value: KeyValueField,
        data_reference: Input,
    }[field.type] ?? Input;
}

// ── Email template affordances ─────────────────────────────────────────────
// A field opts into the rendered-preview + master-detail picker by declaring
// `preview: 'email'` in its schema (see SendEmailAction's `template` field).
// Declarative, so any future field can reuse the same treatment.
const emailPreviewOpen = ref(false);
const emailPickerOpen = ref(false);
const emailFieldHandle = ref(null);

// Selecting another node reuses this component instance (Edit.vue mounts the
// panel with `v-if`, not `:key`), so these three refs survived the switch.
// `emailFieldHandle` in particular is the target `setField()` writes to when a
// template is picked: left pointing at the previous node's field, the pick
// landed on a handle the current node may not even have.
watch(
    () => props.node?.node_key ?? null,
    () => {
        emailPreviewOpen.value = false;
        emailPickerOpen.value = false;
        emailFieldHandle.value = null;
    },
);

function isEmailTemplateField(field) {
    return field.preview === 'email';
}

// Slug currently selected on the field that owns the open modal.
const emailSlug = computed(() =>
    emailFieldHandle.value ? (config.value[emailFieldHandle.value] ?? null) : null,
);

function openEmailPreview(field) {
    emailFieldHandle.value = field.handle;
    emailPreviewOpen.value = true;
}

function openEmailPicker(field) {
    emailFieldHandle.value = field.handle;
    emailPickerOpen.value = true;
}

function onEmailTemplateSelected(slug) {
    if (emailFieldHandle.value) setField(emailFieldHandle.value, slug);
}

function fieldProps(field) {
    const base = {};
    if (isTokenable(field)) base.id = fieldDomId(field);
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
    if (field.type === 'key_value') {
        if (field.key_label) base.keyLabel = field.key_label;
        if (field.value_label) base.valueLabel = field.value_label;
        // Row-level token picker on each value cell (unless the field opts out
        // with `tokenable: false`). Values are the token-bearing side; keys are
        // literal map keys, so only the value gets a picker inside KeyValueField.
        if (field.tokenable !== false) base.variables = nodeVariables.value;
    }
    return base;
}
</script>
