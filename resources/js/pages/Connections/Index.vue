<script setup>
/**
 * The connections listing: every service this site talks to, with how it
 * authenticates and how many operations it offers as action nodes.
 *
 * Rows come in as a prop (client-mode Listing); deleting goes through the JSON
 * API and reloads the page. The delete confirmation fetches the connection
 * first, because only `show` says which automations still use it — and a
 * connection removed from under an automation leaves nodes that fail on
 * every run.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Listing,
    Icon,
    EmptyStateMenu,
    EmptyStateItem,
    DropdownItem,
    CommandPaletteItem,
    Alert,
} from '@statamic/cms/ui';

import DeleteConnectionModal from '../../components/connections/DeleteConnectionModal.vue';
import { errorMessages } from '../../support/serverErrors.js';

const props = defineProps({
    title: { type: String, required: true },
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    createUrl: { type: String, required: true },
    authTypes: { type: Object, default: () => ({}) },
});

const isEmpty = computed(() => props.rows.length === 0);

// Core's Listing has no per-breakpoint columns, but it honours `visible` on a
// column and still lets the user switch one back on. On a phone the name and
// the base URL are what tells two connections apart; the rest starts hidden.
const NARROW_HIDDEN = ['auth_type', 'operations_count'];
const narrow = typeof window !== 'undefined' && window.matchMedia?.('(max-width: 640px)').matches;
const listingColumns = computed(() =>
    props.columns.map((column) =>
        narrow && NARROW_HIDDEN.includes(column.field) ? { ...column, visible: false } : column,
    ),
);
const pendingDelete = ref(null);
const actionErrors = ref([]);

function authLabel(type) {
    return props.authTypes[type] ?? type;
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function deleted() {
    pendingDelete.value = null;
    actionErrors.value = [];
    reloadPage();
}

function deleteFailed(e) {
    pendingDelete.value = null;
    actionErrors.value = errorMessages(e);
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div v-if="isEmpty" class="max-w-page mx-auto" data-connections-empty>
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="link" class="size-5 text-gray-500" />
                {{ title }}
            </h1>
        </header>
        <EmptyStateMenu :heading="__('Connect a service once and use its operations as actions in every automation.')">
            <EmptyStateItem
                :href="createUrl"
                icon="link"
                :heading="__('Create connection')"
                :description="__('A base URL, its credentials, and the requests your automations may send to it.')"
            />
        </EmptyStateMenu>
    </div>

    <div v-else class="max-w-page mx-auto">
        <Header :title="title" icon="link">
            <CommandPaletteItem
                category="Actions"
                :text="__('Create connection')"
                icon="link"
                :url="createUrl"
                v-slot="{ text, url }"
            >
                <Button :href="url" :text="text" variant="primary" />
            </CommandPaletteItem>
        </Header>

        <Alert v-if="actionErrors.length" variant="error" class="mb-4" data-connections-errors>
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in actionErrors" :key="i">{{ message }}</li>
            </ul>
        </Alert>

        <Listing
            :items="rows"
            :columns="listingColumns"
            preferences-prefix="statamic-automations.connections"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="row.edit_url" class="font-medium">{{ row.name }}</Link>
                <div class="text-2xs text-gray-500 font-mono">{{ row.handle }}</div>
            </template>
            <template #cell-base_url="{ row }">
                <span class="font-mono text-xs">{{ row.base_url }}</span>
            </template>
            <template #cell-auth_type="{ row }">
                <span class="text-sm">{{ authLabel(row.auth_type) }}</span>
            </template>
            <template #cell-operations_count="{ row }">
                <span class="tabular-nums">{{ row.operations_count }}</span>
            </template>
            <template #prepended-row-actions="{ row }">
                <DropdownItem :text="__('Edit')" icon="edit" :href="row.edit_url" />
                <DropdownItem
                    :text="__('Delete')"
                    icon="trash"
                    variant="destructive"
                    @click="pendingDelete = row"
                />
            </template>
        </Listing>
    </div>

    <DeleteConnectionModal
        :connection="pendingDelete"
        @deleted="deleted"
        @failed="deleteFailed"
        @cancel="pendingDelete = null"
    />
</template>
