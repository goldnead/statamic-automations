<script setup>
import { computed } from 'vue';
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
} from '@statamic/cms/ui';
import axios from 'axios';

const props = defineProps({
    title: { type: String, required: true },
    rows: { type: Array, required: true },
    createUrl: { type: String, required: true },
    apiBase: { type: String, required: true },
    canCreate: { type: Boolean, default: false },
});

const isEmpty = computed(() => props.rows.length === 0);

const columns = [
    { key: 'name', label: __('Name'), required: true },
    { key: 'enabled', label: __('Status') },
    { key: 'runs_count', label: __('Runs') },
    { key: 'last_run_at', label: __('Last run') },
    { key: 'updated_at', label: __('Updated') },
];

function statusColor(enabled) {
    return enabled ? 'green' : 'gray';
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}

async function toggleEnabled(row) {
    const url = props.apiBase + '/automations/' + row.id + (row.enabled ? '/disable' : '/enable');
    try {
        const { data } = await axios.post(url);
        if (data?.ok === false) {
            window?.Statamic?.$toast?.error?.(data.message ?? __('Could not change state.'));
        } else {
            reloadPage();
        }
    } catch (e) {
        window?.Statamic?.$toast?.error?.(e?.response?.data?.message ?? __('Request failed.'));
    }
}

async function duplicate(row) {
    try {
        await axios.post(props.apiBase + '/automations/' + row.id + '/duplicate');
        window?.Statamic?.$toast?.success?.(__('Duplicated.'));
        reloadPage();
    } catch (e) {
        window?.Statamic?.$toast?.error?.(__('Duplicate failed.'));
    }
}

async function destroy(row) {
    if (!window.confirm(__('Are you sure you want to delete ":name"?', { name: row.name }))) {
        return;
    }
    try {
        await axios.delete(props.apiBase + '/automations/' + row.id);
        window?.Statamic?.$toast?.success?.(__('Deleted.'));
        reloadPage();
    } catch (e) {
        window?.Statamic?.$toast?.error?.(__('Delete failed.'));
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
                <Icon name="hammer" class="size-5 text-gray-500" />
                {{ title }}
            </h1>
        </header>
        <EmptyStateMenu :heading="__('Build your first automation')">
            <EmptyStateItem
                v-if="canCreate"
                :href="createUrl"
                icon="hammer"
                :heading="__('Create automation')"
                :description="__('Drag triggers, conditions and actions onto a visual canvas.')"
            />
            <EmptyStateItem
                :href="createUrl.replace('/create', '/templates')"
                icon="copy"
                :heading="__('Start from a template')"
                :description="__('Pick from eight built-in patterns and customize.')"
            />
        </EmptyStateMenu>
        <DocsCallout :topic="__('Statamic Automations')" url="https://github.com/goldnead/statamic-automations" />
    </div>

    <div v-else class="max-w-page mx-auto">
        <Header :title="title" icon="hammer">
            <Button
                v-if="canCreate"
                :href="createUrl"
                :text="__('Create automation')"
                variant="primary"
            />
        </Header>

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
                    icon="toggle"
                    @click="toggleEnabled(row)"
                />
                <DropdownItem
                    v-if="row.can_edit"
                    :text="__('Duplicate')"
                    icon="copy"
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
                    @click="destroy(row)"
                />
            </template>
        </Listing>
    </div>
</template>
