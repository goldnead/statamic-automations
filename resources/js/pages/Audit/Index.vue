<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Listing, Badge, EmptyStateMenu, EmptyStateItem } from '@statamic/cms/ui';

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

        <EmptyStateMenu v-if="logs.length === 0" :heading="__('No audit entries yet')">
            <EmptyStateItem
                :href="automationsUrl"
                icon="workflow"
                :heading="__('Browse automations')"
                :description="__('Every create, update, enable, disable, delete and revert lands here.')"
            />
        </EmptyStateMenu>

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
