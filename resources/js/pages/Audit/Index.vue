<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Listing, Badge } from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    logs: { type: Array, required: true },
    columns: { type: Array, required: true },
    automationsUrl: { type: String, required: true },
});

const tone = {
    created: 'green',
    updated: 'blue',
    enabled: 'green',
    disabled: 'default',
    deleted: 'red',
    reverted: 'orange',
};
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="list-ul" />

        <div
            v-if="logs.length === 0"
            class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm p-6 text-center text-sm text-gray-500"
        >
            {{ __('No audit entries yet.') }}
        </div>

        <Listing
            v-else
            :items="logs"
            :columns="columns"
            preferences-prefix="statamic-automations.audit"
        >
            <template #cell-action="{ row }">
                <Badge :color="tone[row.action] || 'default'" :text="row.action" />
            </template>
            <template #cell-created_at="{ row }">
                <span class="text-2xs text-gray-500">{{ row.created_at ? new Date(row.created_at).toLocaleString() : '—' }}</span>
            </template>
        </Listing>
    </div>
</template>
