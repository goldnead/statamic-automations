<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Listing,
    Badge,
    Icon,
    EmptyStateMenu,
    EmptyStateItem,
    DocsCallout,
    DropdownItem,
    Alert,
    ConfirmationModal,
} from '@statamic/cms/ui';
import axios from 'axios';

import { errorMessages, firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    title: { type: String, required: true },
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    createUrl: { type: String, required: true },
    templatesUrl: { type: String, required: true },
    apiBase: { type: String, required: true },
    // How many templates the registry actually offers. Counted server-side so
    // the empty state cannot drift from the catalog the way a hardcoded
    // "eight" did while eleven templates shipped.
    templateCount: { type: Number, default: 0 },
    canCreate: { type: Boolean, default: false },
});

const isEmpty = computed(() => props.rows.length === 0);

function statusColor(enabled) {
    return enabled ? 'green' : 'default';
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}

// What the server said when a row action was refused. A toast is a two-second
// window over a message the user may need to act on — a refused enable comes
// back with the per-node reasons, a refused delete with the permission it
// wanted — so it is kept on screen here as well.
const actionErrors = ref([]);

function report(e, fallback) {
    actionErrors.value = errorMessages(e);
    window?.Statamic?.$toast?.error?.(firstMessage(e, fallback));
}

async function toggleEnabled(row) {
    const url = props.apiBase + '/automations/' + row.id + (row.enabled ? '/disable' : '/enable');
    try {
        const { data } = await axios.post(url);
        // The `ok === false` shape is returned with HTTP 422, which axios
        // rejects — so this branch never ran and the reasons that came with it
        // were dropped. It is kept for a 200 that reports a refusal in-band.
        if (data?.ok === false) {
            actionErrors.value = (data.issues ?? []).map((i) => i?.message ?? i).filter(Boolean);
            window?.Statamic?.$toast?.error?.(data.message || __('Could not change state.'));
        } else {
            actionErrors.value = [];
            reloadPage();
        }
    } catch (e) {
        report(e, __('Request failed.'));
    }
}

async function duplicate(row) {
    try {
        await axios.post(props.apiBase + '/automations/' + row.id + '/duplicate');
        actionErrors.value = [];
        window?.Statamic?.$toast?.success?.(__('Duplicated.'));
        reloadPage();
    } catch (e) {
        // Bound `e` and then ignored it: the server's reason — a permission
        // name, a validation message — was replaced by "Duplicate failed."
        report(e, __('Duplicate failed.'));
    }
}

// Deletion is confirmed in a native CP modal, not window.confirm. A browser
// chrome dialog reads as third-party in the middle of a CP flow, cannot be
// styled or translated consistently, and is suppressed outright in some
// embedded contexts — where the click then silently does nothing.
const pendingDelete = ref(null);
const deleting = ref(false);

const deletePrompt = computed(() =>
    pendingDelete.value
        ? __('Are you sure you want to delete ":name"?', { name: pendingDelete.value.name })
        : '',
);

function confirmDestroy(row) {
    pendingDelete.value = row;
}

async function destroy() {
    const row = pendingDelete.value;
    if (!row) return;

    deleting.value = true;
    try {
        await axios.delete(props.apiBase + '/automations/' + row.id);
        actionErrors.value = [];
        window?.Statamic?.$toast?.success?.(__('Deleted.'));
        pendingDelete.value = null;
        reloadPage();
    } catch (e) {
        report(e, __('Delete failed.'));
        pendingDelete.value = null;
    } finally {
        deleting.value = false;
    }
}

function exportJson(row) {
    window.open(props.apiBase + '/automations/' + row.id + '/export', '_blank');
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div v-if="isEmpty" class="max-w-page mx-auto">
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-3">
                <Icon name="workflow" class="size-5 text-gray-500" />
                {{ title }}
            </h1>
        </header>
        <EmptyStateMenu :heading="__('Build your first automation')">
            <EmptyStateItem
                v-if="canCreate"
                :href="createUrl"
                icon="workflow"
                :heading="__('Create automation')"
                :description="__('Add triggers, conditions and actions to a visual canvas.')"
            />
            <EmptyStateItem
                :href="templatesUrl"
                icon="duplicate"
                :heading="__('Start from a template')"
                :description="__(':count built-in patterns to pick from and customize.', { count: templateCount })"
            />
        </EmptyStateMenu>
        <DocsCallout :topic="__('Statamic Automations')" url="https://github.com/goldnead/statamic-automations" />
    </div>

    <div v-else class="max-w-page mx-auto">
        <Header :title="title" icon="workflow">
            <Button
                v-if="canCreate"
                :href="createUrl"
                :text="__('Create automation')"
                variant="primary"
            />
        </Header>

        <!-- What the server said when a row action was refused. There is no
             field on this page to hang it on, so everything comes here. -->
        <Alert
            v-if="actionErrors.length"
            variant="error"
            class="mb-4"
            data-automations-form-errors
        >
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in actionErrors" :key="i">{{ message }}</li>
            </ul>
        </Alert>

        <Listing
            :items="rows"
            :columns="columns"
            preferences-prefix="statamic-automations.automations"
        >
            <template #cell-name="{ row }">
                <Link :href="row.edit_url" class="font-medium">{{ row.name }}</Link>
                <div class="text-2xs text-gray-500 font-mono">{{ row.handle }}</div>
            </template>
            <template #cell-enabled="{ row }">
                <Badge
                    :color="statusColor(row.enabled)"
                    :text="row.enabled ? __('Active') : __('Disabled')"
                />
            </template>
            <template #cell-runs_count="{ row }">{{ row.runs_count }}</template>
            <template #cell-last_run_at="{ row }">
                <span v-if="row.last_run_at" class="text-2xs">{{ new Date(row.last_run_at).toLocaleString() }}</span>
                <span v-else class="text-2xs text-gray-500">{{ __('Never') }}</span>
            </template>
            <template #cell-updated_at="{ row }">
                <span class="text-2xs">{{ new Date(row.updated_at).toLocaleString() }}</span>
            </template>
            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="row.can_edit"
                    :text="__('Edit')"
                    :href="row.edit_url"
                    icon="cog"
                />
                <DropdownItem
                    v-if="row.can_enable"
                    :text="row.enabled ? __('Disable') : __('Enable')"
                    icon="fieldtype-toggle"
                    @click="toggleEnabled(row)"
                />
                <DropdownItem
                    v-if="row.can_edit"
                    :text="__('Duplicate')"
                    icon="duplicate"
                    @click="duplicate(row)"
                />
                <DropdownItem
                    :text="__('Export JSON')"
                    icon="download"
                    @click="exportJson(row)"
                />
                <DropdownItem
                    v-if="row.can_delete"
                    :text="__('Delete')"
                    icon="trash"
                    @click="confirmDestroy(row)"
                />
            </template>
        </Listing>

        <ConfirmationModal
            :open="pendingDelete !== null"
            :title="__('Delete automation')"
            :body-text="deletePrompt"
            :button-text="__('Delete')"
            :busy="deleting"
            danger
            @update:open="pendingDelete = $event ? pendingDelete : null"
            @confirm="destroy"
            @cancel="pendingDelete = null"
        />
    </div>
</template>
