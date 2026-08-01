<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header,
    Listing,
    Badge,
    Select,
    Switch,
    EmptyStateMenu,
    EmptyStateItem,
    DropdownItem,
    Icon,
} from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    statusOptions: { type: Array, required: true },
    automationsUrl: { type: String, required: true },
    canRetry: { type: Boolean, default: false },
});

const status = ref(props.filters.status ?? '');
const isTest = ref(props.filters.is_test ?? null);

function statusColor(s) {
    return {
        success: 'green',
        failed: 'red',
        stopped: 'amber',
        running: 'blue',
        queued: 'default',
        waiting: 'blue',
        cancelled: 'default',
    }[s] ?? 'default';
}

function applyFilters() {
    router.get(window.location.pathname, {
        status: status.value || undefined,
        is_test: isTest.value === null ? undefined : isTest.value ? 1 : 0,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto">
        <Header :title="title" icon="history" />

        <div class="flex items-center gap-3 mb-3 px-1">
            <Select
                v-model="status"
                :options="statusOptions"
                :placeholder="__('Filter by status')"
                class="min-w-[180px]"
                @update:model-value="applyFilters"
            />
            <label class="flex items-center gap-2 text-sm">
                <Switch v-model="isTest" @update:model-value="applyFilters" />
                <span>{{ __('Test runs only') }}</span>
            </label>
        </div>

        <EmptyStateMenu v-if="rows.length === 0" :heading="__('No runs yet')">
            <EmptyStateItem
                :href="automationsUrl"
                icon="workflow"
                :heading="__('Browse automations')"
                :description="__('Trigger an automation or run a test to see runs here.')"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            :items="rows"
            :columns="columns"
            :allow-search="false"
            preferences-prefix="statamic-automations.runs"
        >
            <template #cell-id="{ row }">
                <Link :href="row.show_url" class="font-mono text-xs">#{{ row.id }}</Link>
                <Badge v-if="row.is_test" color="blue" :text="__('test')" class="ml-1" />
            </template>
            <template #cell-automation_name="{ row }">
                {{ row.automation_name ?? '—' }}
            </template>
            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status" />
            </template>
            <template #cell-trigger_type="{ row }">
                <code class="text-xs text-gray-500">{{ row.trigger_type ?? '—' }}</code>
            </template>
            <template #cell-duration_ms="{ row }">
                <span v-if="row.duration_ms != null" class="text-2xs">{{ row.duration_ms }} ms</span>
                <span v-else class="text-2xs text-gray-500">—</span>
            </template>
            <template #cell-started_at="{ row }">
                <span class="text-2xs">{{ row.started_at ? new Date(row.started_at).toLocaleString() : '—' }}</span>
            </template>
            <template #prepended-row-actions="{ row }">
                <DropdownItem :text="__('View')" :href="row.show_url" icon="eye" />
            </template>
        </Listing>
    </div>
</template>
