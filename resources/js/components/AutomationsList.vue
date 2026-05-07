<template>
    <div class="sa-list">
        <header class="sa-list__header">
            <div>
                <h1 class="sa-list__title">Automations</h1>
                <p class="sa-list__subtitle">{{ items.length }} automation{{ items.length === 1 ? '' : 's' }}</p>
            </div>
            <div class="sa-list__header-actions">
                <a href="import" class="sa-btn sa-btn--secondary">Import JSON</a>
                <a href="create" class="sa-btn">New automation</a>
            </div>
        </header>

        <div class="sa-list__filters">
            <input
                type="search"
                class="sa-list__search"
                placeholder="Search by name or handle…"
                v-model="search"
                @keyup.enter="load"
            />
            <select class="sa-list__filter" v-model="enabled" @change="load">
                <option value="">Any status</option>
                <option value="true">Enabled</option>
                <option value="false">Disabled</option>
            </select>
        </div>

        <LoadingSpinner v-if="loading" label="Loading automations…" />

        <ErrorMessage
            v-else-if="error"
            :message="error"
            level="error"
            title="Couldn't load automations"
        >
            <template #actions>
                <button type="button" class="sa-btn" @click="load">Retry</button>
            </template>
        </ErrorMessage>

        <EmptyState
            v-else-if="!items.length"
            title="No automations yet"
            message="Pick a template to get started, or build one from scratch."
        >
            <template #actions>
                <a href="templates" class="sa-btn sa-btn--secondary">Browse templates</a>
                <a href="create" class="sa-btn">Create from scratch</a>
            </template>
        </EmptyState>

        <table v-else class="sa-list__table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Handle</th>
                    <th>Enabled</th>
                    <th>Runs</th>
                    <th>Last run</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="auto in items" :key="auto.id">
                    <td>
                        <a :href="auto.id" class="sa-list__name">{{ auto.name }}</a>
                    </td>
                    <td><code>{{ auto.handle }}</code></td>
                    <td>
                        <span :class="['sa-pill', auto.enabled ? 'sa-pill--green' : 'sa-pill--gray']">
                            {{ auto.enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </td>
                    <td>{{ auto.runs_count ?? '—' }}</td>
                    <td>{{ formatDate(auto.last_run_at) }}</td>
                    <td>{{ formatDate(auto.updated_at) }}</td>
                    <td class="sa-list__actions">
                        <button type="button" class="sa-btn sa-btn--ghost" @click="duplicate(auto)">Duplicate</button>
                        <button type="button" class="sa-btn sa-btn--ghost" @click="exportOne(auto)">Export</button>
                        <button type="button" class="sa-btn sa-btn--danger" @click="destroy(auto)">Delete</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <Toast v-if="toastState.message" :key="toastState.seq" :message="toastState.message" :level="toastState.level" />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api/client.js';
import EmptyState from './ui/EmptyState.vue';
import LoadingSpinner from './ui/LoadingSpinner.vue';
import ErrorMessage from './ui/ErrorMessage.vue';
import Toast from './ui/Toast.vue';
import { toast, useToastState } from '../composables/useToast.js';

const items = ref([]);
const loading = ref(false);
const error = ref(null);
const search = ref('');
const enabled = ref('');
const toastState = useToastState();

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const params = {};
        if (search.value) params.search = search.value;
        if (enabled.value) params.enabled = enabled.value === 'true' ? 1 : 0;
        const response = await api.automations.list(params);
        items.value = response.data ?? [];
    } catch (e) {
        error.value = e?.response?.data?.message ?? 'Couldn\'t load automations. Check your connection and permissions.';
    } finally {
        loading.value = false;
    }
}

async function duplicate(auto) {
    try {
        await api.automations.duplicate(auto.id);
        toast.success(`Duplicated “${auto.name}”`);
        await load();
    } catch (e) {
        toast.error(e?.response?.data?.message ?? 'Duplication failed.');
    }
}

async function destroy(auto) {
    if (!window.confirm(`Delete "${auto.name}"? This cannot be undone.`)) return;
    try {
        await api.automations.destroy(auto.id);
        toast.success(`Deleted “${auto.name}”`);
        await load();
    } catch (e) {
        toast.error(e?.response?.data?.message ?? 'Delete failed.');
    }
}

async function exportOne(auto) {
    try {
        const payload = await api.exports.download(auto.id);
        downloadJson(`${auto.handle}.json`, payload);
        toast.success(`Exported “${auto.name}”`);
    } catch (e) {
        toast.error(e?.response?.data?.message ?? 'Export failed.');
    }
}

function downloadJson(filename, data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function formatDate(value) {
    if (!value) return '—';
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
}
</script>
