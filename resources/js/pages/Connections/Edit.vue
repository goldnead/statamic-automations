<script setup>
/**
 * Create or edit one connection: its base data, its credentials, and the
 * operations it offers as action nodes. Laid out like Webhook Manager's
 * outbound screen — the family's form with tabs and a test button.
 *
 * Credentials are write-only. The page receives `auth_config` as placeholders
 * (which fields are set, never what is in them), so every credential input
 * starts empty; left empty it is sent empty, and the API keeps the stored
 * value. Typing into one replaces it.
 *
 * "Test" calls the stored connection, not the form: it sends one GET to
 * `base_url + test_path` with the saved credentials and answers with the
 * status and the time it took, or with why it could not connect — including
 * the refusal to call a private or local address.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Badge,
    Dropdown,
    DropdownMenu,
    DropdownItem,
    Alert,
    Panel,
    Card,
    Field,
    Input,
    Select,
    Tabs,
    TabList,
    TabTrigger,
    TabContent,
    Listing,
    Description,
    ConfirmationModal,
    CommandPaletteItem,
} from '@statamic/cms/ui';

import KeyValueField from '../../components/builder/KeyValueField.vue';
import OperationStack from '../../components/connections/OperationStack.vue';
import DeleteConnectionModal from '../../components/connections/DeleteConnectionModal.vue';
import { errorBag, errorMessages, firstMessage } from '../../support/serverErrors.js';
import { handleFrom } from '../../support/handle.js';

const props = defineProps({
    title: { type: String, required: true },
    connection: { type: Object, required: true },
    isNew: { type: Boolean, default: false },
    indexUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    operationsUrl: { type: String, default: null },
    authTypes: { type: Object, required: true },
    authFields: { type: Object, required: true },
    methods: { type: Array, required: true },
    inputTypes: { type: Array, required: true },
    placeholder: { type: String, default: '••••••••' },
});

// The stored connection as the API last described it. Replaced after a save,
// so the placeholders and the operation list follow what is really stored.
const stored = ref({ ...props.connection });

const form = ref(fromConnection(props.connection));
const errors = ref({});
const saving = ref(false);
const handleTouched = ref(!props.isNew);

function fromConnection(connection) {
    return {
        name: connection.name ?? '',
        handle: connection.handle ?? '',
        base_url: connection.base_url ?? '',
        test_path: connection.test_path ?? '',
        timeout: connection.timeout ?? 15,
        default_headers: connection.default_headers ?? {},
        auth_type: connection.auth_type ?? 'none',
        auth_config: {},
    };
}

const authTypeOptions = computed(() =>
    Object.entries(props.authTypes).map(([value, label]) => ({ value, label })),
);

const fieldLabels = {
    name: __('Header name'),
    value: __('Header value'),
    token: __('Token'),
    username: __('Username'),
    password: __('Password'),
};

// Which of the auth fields hold a secret — those get a password input.
const secretFields = ['value', 'token', 'password'];

const currentAuthFields = computed(() => props.authFields[form.value.auth_type] ?? []);

// A field shows "stored" only while the auth type is the stored one: switching
// the type drops the old credentials on save.
function isStored(key) {
    return form.value.auth_type === stored.value.auth_type && Boolean(stored.value.auth_config?.[key]);
}

// Where a credential comes from, per field. Generic first, Slack as the one
// example everybody has seen.
const fieldInstructions = {
    token: __('Create it at the service, usually under Settings, API or Developer. Slack: create an app at api.slack.com, then copy the Bot User OAuth Token (xoxb-…) from OAuth & Permissions.'),
    name: __('The header the service expects the key in, e.g. X-Api-Key. The service documentation names it.'),
    value: __('The API key from the service, usually under Settings, API or Developer.'),
    username: __('The user the service gave you for API access.'),
    password: __('The password or app password for that user.'),
};

function authInstructions(key) {
    const base = fieldInstructions[key] ?? '';
    return isStored(key) ? `${base} ${__('Stored. Leave empty to keep it; type to replace it.')}`.trim() : base || null;
}

watch(
    () => form.value.name,
    (name) => {
        if (!handleTouched.value) form.value.handle = handleFrom(name);
    },
);

// ── Unsaved changes ─────────────────────────────────────────────────────
// Core's dirty state owns the leave-page warnings (Inertia links, reload,
// back button); this page only says whether it has something unsaved.

const DIRTY = 'automations-connection';
let snapshot = JSON.stringify(form.value);

const isDirty = computed(() => JSON.stringify(form.value) !== snapshot);

watch(isDirty, (dirty) => {
    const state = globalThis.Statamic?.$dirty;
    if (!state) return;
    dirty ? state.add(DIRTY) : state.remove(DIRTY);
});

function markClean() {
    snapshot = JSON.stringify(form.value);
    // The computed only re-reads the form; the snapshot is not reactive.
    form.value = { ...form.value };
    globalThis.Statamic?.$dirty?.remove?.(DIRTY);
}

onBeforeUnmount(() => globalThis.Statamic?.$dirty?.remove?.(DIRTY));

// ── Tabs and where the errors are ───────────────────────────────────────

const activeTab = ref('connection');

const tabFields = {
    connection: ['name', 'handle', 'base_url', 'test_path', 'timeout', 'default_headers'],
    access: ['auth_type', 'auth_config'],
};

const tabsWithErrors = computed(() => {
    const tabs = new Set();
    for (const [tab, keys] of Object.entries(tabFields)) {
        if (Object.keys(errors.value).some((k) => keys.some((key) => k === key || k.startsWith(key + '.')))) {
            tabs.add(tab);
        }
    }
    return tabs;
});

// ── Save ────────────────────────────────────────────────────────────────

function payload() {
    const f = form.value;
    const authConfig = {};
    for (const key of currentAuthFields.value) authConfig[key] = f.auth_config[key] ?? '';

    return {
        name: f.name,
        handle: f.handle,
        base_url: f.base_url,
        test_path: f.test_path || null,
        timeout: f.timeout === '' || f.timeout === null ? null : Number(f.timeout),
        default_headers: f.default_headers,
        auth_type: f.auth_type,
        auth_config: authConfig,
    };
}

// Saved over axios, not `router.post`: the API is JSON and answers with the
// stored connection, which this page swaps in without a reload (and so keeps
// the open tab and the test result). The progress bar is started by hand for
// that reason, and the dirty state is cleared before any navigation.
async function save() {
    saving.value = true;
    globalThis.Statamic?.$progress?.start?.('connection-save');
    try {
        if (props.isNew) {
            const { data } = await axios.post(props.storeUrl, payload());
            globalThis.Statamic?.$toast?.success?.(__('Saved'));
            markClean();
            // Core switches its leave-page prompt off in a watcher, which runs
            // after this tick; visiting first would ask "leave unsaved?"
            // about the record that was just saved.
            await nextTick();
            router.visit(data.data.edit_url);
            return;
        }

        const { data } = await axios.patch(stored.value.show_url, payload());
        stored.value = data.data;
        form.value.auth_config = {};
        errors.value = {};
        markClean();
        globalThis.Statamic?.$toast?.success?.(__('Saved'));
    } catch (e) {
        errors.value = errorBag(e);
        const first = ['connection', 'access'].find((t) => tabsWithErrors.value.has(t));
        if (first) activeTab.value = first;
        // A field's own message ("This field is required.") says nothing in
        // a toast; the fields say it where it belongs. Core's toast for this.
        window?.Statamic?.$toast?.error?.(
            Object.keys(errors.value).length ? __('Something went wrong') : firstMessage(e, __('Something went wrong')),
        );
    } finally {
        saving.value = false;
        globalThis.Statamic?.$progress?.complete?.('connection-save');
    }
}

// Messages for keys no field on this page shows.
const shownKeys = [...tabFields.connection, ...tabFields.access];
const otherErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([key]) => !shownKeys.some((k) => key === k || key.startsWith(k + '.')))
        .map(([, message]) => message),
);

// ── Test ────────────────────────────────────────────────────────────────

const testing = ref(false);
const testResult = ref(null);

async function runTest() {
    testing.value = true;
    testResult.value = null;
    try {
        // The form as it stands, saved or not: the API tests these values and
        // stores nothing. Empty credentials mean the stored ones, as on save.
        const { name: _name, handle: _handle, ...values } = payload();
        const { data } = await axios.post(stored.value.test_url, values);
        testResult.value = data;
    } catch (e) {
        testResult.value = { ok: false, status: null, duration_ms: null, error: firstMessage(e, __('Request failed.')) };
    } finally {
        testing.value = false;
    }
}

const testHeading = computed(() => {
    const r = testResult.value;
    if (!r) return '';
    if (r.ok) return __('Connection works');
    return r.status ? __('The service answered with an error') : __('Could not connect');
});

const testText = computed(() => {
    const r = testResult.value;
    if (!r) return '';
    // The reason first: "0 ms" in front of a refusal reads like a result.
    if (r.error) return r.error;
    const parts = [];
    if (r.status) parts.push(`HTTP ${r.status}`);
    if (r.duration_ms !== null && r.duration_ms !== undefined) parts.push(`${r.duration_ms} ms`);
    return parts.join(' · ');
});

// ── Operations ──────────────────────────────────────────────────────────

const operations = computed(() => stored.value.operations ?? []);

const operationColumns = [
    { field: 'name', label: __('Name'), visible: true, sortable: true },
    { field: 'method', label: __('Method'), visible: true, sortable: true },
    { field: 'path', label: __('Path'), visible: true, sortable: false },
    { field: 'inputs', label: __('Inputs'), visible: true, sortable: false },
];

const stackOpen = ref(false);
const editingOperation = ref(null);

function openOperation(operation = null) {
    editingOperation.value = operation;
    stackOpen.value = true;
}

function operationSaved(operation) {
    const list = [...operations.value];
    const index = list.findIndex((o) => o.id === operation.id);
    if (index === -1) list.push(operation);
    else list[index] = operation;
    stored.value = { ...stored.value, operations: list, operations_count: list.length };
}

const pendingOperationDelete = ref(null);
const deletingOperation = ref(false);
const actionErrors = ref([]);

async function destroyOperation() {
    const operation = pendingOperationDelete.value;
    deletingOperation.value = true;
    try {
        await axios.delete(`${props.operationsUrl}/${operation.id}`);
        const list = operations.value.filter((o) => o.id !== operation.id);
        stored.value = { ...stored.value, operations: list, operations_count: list.length };
        actionErrors.value = [];
        window?.Statamic?.$toast?.success?.(__('Deleted.'));
    } catch (e) {
        actionErrors.value = errorMessages(e);
    } finally {
        deletingOperation.value = false;
        pendingOperationDelete.value = null;
    }
}

// ── Delete the connection ───────────────────────────────────────────────

const pendingDelete = ref(null);

async function connectionDeleted() {
    globalThis.Statamic?.$dirty?.remove?.(DIRTY);
    await nextTick();
    router.visit(props.indexUrl);
}

function deleteFailed(e) {
    pendingDelete.value = null;
    actionErrors.value = errorMessages(e);
}
</script>

<template>
    <Head :title="[isNew ? title : stored.name, __('Connections'), __('Statamic Automations')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="isNew ? title : stored.name" icon="link">
            <Dropdown v-if="!isNew">
                <DropdownMenu>
                    <DropdownItem
                        variant="destructive"
                        icon="trash"
                        :text="__('Delete connection')"
                        @click="pendingDelete = stored"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button
                v-if="!isNew"
                :loading="testing"
                :text="__('Test connection')"
                data-connection-test
                @click="runTest"
            />
            <CommandPaletteItem
                category="Actions"
                :text="isNew ? __('Create connection') : __('Save')"
                icon="save"
                :action="save"
                prioritize
                v-slot="{ text }"
            >
                <Button variant="primary" :text="text" :loading="saving" data-connection-save @click="save" />
            </CommandPaletteItem>
        </Header>

        <Alert v-if="otherErrors.length || actionErrors.length" variant="error" class="mb-4" data-connection-errors>
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in [...otherErrors, ...actionErrors]" :key="i">{{ message }}</li>
            </ul>
        </Alert>

        <Alert
            v-if="testResult"
            :variant="testResult.ok ? 'success' : 'error'"
            :heading="testHeading"
            :text="testText"
            class="mb-4"
            data-connection-test-result
        />

        <Tabs v-model="activeTab">
            <TabList>
                <TabTrigger name="connection">
                    {{ __('Connection') }}
                    <Badge v-if="tabsWithErrors.has('connection')" color="red" pill class="ms-1.5" text="!" :aria-label="__('This tab has errors')" />
                </TabTrigger>
                <TabTrigger name="access">
                    {{ __('Access') }}
                    <Badge v-if="tabsWithErrors.has('access')" color="red" pill class="ms-1.5" text="!" :aria-label="__('This tab has errors')" />
                </TabTrigger>
                <TabTrigger name="operations">
                    {{ __('Operations') }}
                </TabTrigger>
            </TabList>

            <TabContent name="connection">
                <Panel class="mt-4">
                    <Card class="space-y-6">
                        <div class="grid sm:grid-cols-2 gap-6 *:min-w-0">
                            <Field id="name" :label="__('Name')" required :error="errors.name">
                                <Input id="name" v-model="form.name" :focus="isNew" />
                            </Field>
                            <Field
                                id="handle"
                                :label="__('Handle')"
                                required
                                :error="errors.handle"
                                instructions-below
                                :instructions="__('Filled in from the name. Lowercase letters, digits and underscores.')"
                            >
                                <Input id="handle" v-model="form.handle" class="font-mono" @update:model-value="handleTouched = true" />
                            </Field>
                        </div>
                        <Field
                            id="base_url"
                            :label="__('Base URL')"
                            required
                            :error="errors.base_url"
                            :instructions="__('The address all requests of this service start with, e.g. https://slack.com/api. Private and local addresses are refused.')"
                        >
                            <Input id="base_url" v-model="form.base_url" type="url" class="font-mono" placeholder="https://slack.com/api" />
                        </Field>
                        <div class="grid sm:grid-cols-2 gap-6 *:min-w-0">
                            <Field
                                id="test_path"
                                :label="__('Test path')"
                                :error="errors.test_path"
                                :instructions="__('Where “Test connection” sends a harmless request, e.g. /auth.test for Slack. Empty means the base URL itself.')"
                            >
                                <Input id="test_path" v-model="form.test_path" class="font-mono" placeholder="/auth.test" />
                            </Field>
                            <Field
                                id="timeout"
                                :label="__('Timeout')"
                                :error="errors.timeout"
                                :instructions="__('Seconds, 1 to 120.')"
                            >
                                <Input id="timeout" v-model="form.timeout" type="number" min="1" max="120" />
                            </Field>
                        </div>
                        <Field
                            :label="__('Default headers')"
                            :error="errors.default_headers"
                            :instructions="__('Extra details sent with every request, e.g. Accept → application/json. Most services need none. Keys and passwords belong on the Access tab.')"
                        >
                            <KeyValueField v-model="form.default_headers" :key-label="__('Header')" />
                        </Field>
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="access">
                <Panel class="mt-4">
                    <Card class="space-y-6">
                        <Field id="auth_type" :label="__('Authentication')" required :error="errors.auth_type">
                            <Select id="auth_type" v-model="form.auth_type" :options="authTypeOptions" />
                        </Field>

                        <Description
                            v-if="!isNew && stored.auth_configured && form.auth_type !== stored.auth_type"
                            :text="__('On save, the stored credentials are discarded.')"
                        />

                        <Field
                            v-for="key in currentAuthFields"
                            :id="`auth_${key}`"
                            :key="key"
                            :label="fieldLabels[key] ?? key"
                            :error="errors[`auth_config.${key}`]"
                            :instructions="authInstructions(key)"
                        >
                            <Input
                                :id="`auth_${key}`"
                                v-model="form.auth_config[key]"
                                :type="secretFields.includes(key) ? 'password' : 'text'"
                                :placeholder="isStored(key) ? __('Stored credential') : ''"
                                :viewable="secretFields.includes(key) && !isStored(key)"
                                autocomplete="off"
                                :data-auth-field="key"
                            />
                        </Field>

                        <Description
                            v-if="currentAuthFields.length === 0"
                            :text="__('Requests go out without credentials.')"
                        />
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="operations">
                <div class="mt-4">
                    <Panel v-if="isNew">
                        <Card>
                            <Description :text="__('Save the connection first, then add the operations it offers.')" />
                        </Card>
                    </Panel>

                    <template v-else>
                        <div class="flex items-center justify-between gap-4 mb-3">
                            <Description :text="__('Each operation is an action in the automation builder.')" />
                            <Button icon="plus" :text="__('Add operation')" data-operation-add @click="openOperation()" />
                        </div>

                        <Panel v-if="operations.length === 0">
                            <Card>
                                <Description :text="__('No operations yet.')" />
                            </Card>
                        </Panel>

                        <Listing
                            v-else
                            :items="operations"
                            :columns="operationColumns"
                            :allow-presets="false"
                            :allow-search="false"
                            :allow-customizing-columns="false"
                            :show-pagination-totals="false"
                            :show-pagination-page-links="false"
                            :show-pagination-per-page-selector="false"
                        >
                            <template #cell-name="{ row }">
                                <button
                                    type="button"
                                    data-interactive
                                    class="cursor-pointer text-start font-medium hover:underline"
                                    :data-operation-open="row.handle"
                                    @click="openOperation(row)"
                                >
                                    {{ row.name }}
                                </button>
                                <div class="text-2xs text-gray-500 font-mono">{{ row.node_type }}</div>
                            </template>
                            <template #cell-method="{ row }">
                                <Badge pill :text="row.method" />
                            </template>
                            <template #cell-path="{ row }">
                                <span class="font-mono text-xs">{{ row.path }}</span>
                            </template>
                            <template #cell-inputs="{ row }">
                                <span class="tabular-nums">{{ (row.inputs ?? []).length }}</span>
                            </template>
                            <template #prepended-row-actions="{ row }">
                                <DropdownItem :text="__('Edit')" icon="edit" @click="openOperation(row)" />
                                <DropdownItem
                                    :text="__('Delete')"
                                    icon="trash"
                                    variant="destructive"
                                    @click="pendingOperationDelete = row"
                                />
                            </template>
                        </Listing>
                    </template>
                </div>
            </TabContent>
        </Tabs>
    </div>

    <OperationStack
        v-if="operationsUrl"
        v-model:open="stackOpen"
        :operation="editingOperation"
        :operations-url="operationsUrl"
        :methods="methods"
        :input-types="inputTypes"
        @saved="operationSaved"
    />

    <ConfirmationModal
        :open="pendingOperationDelete !== null"
        :title="__('Delete operation')"
        :body-text="pendingOperationDelete ? __('Delete the operation :name? Automations that use it will fail at this step.', { name: pendingOperationDelete.name }) : ''"
        :button-text="__('Delete')"
        :busy="deletingOperation"
        danger
        @update:open="!$event && (pendingOperationDelete = null)"
        @confirm="destroyOperation"
        @cancel="pendingOperationDelete = null"
    />

    <DeleteConnectionModal
        :connection="pendingDelete"
        @deleted="connectionDeleted"
        @failed="deleteFailed"
        @cancel="pendingDelete = null"
    />
</template>
