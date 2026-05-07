<template>
    <div class="sa-list">
        <header class="sa-list__header">
            <h1 class="sa-list__title">Automations</h1>
            <a href="create" class="sa-btn">New automation</a>
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

        <div v-if="loading" class="sa-list__loading">Loading…</div>
        <div v-else-if="!items.length" class="sa-list__empty">
            <p>No automations yet. Pick a template or build one from scratch.</p>
            <a href="templates" class="sa-btn sa-btn--secondary">Browse templates</a>
        </div>
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
                        <button type="button" class="sa-btn sa-btn--ghost" @click="duplicate(auto.id)">Duplicate</button>
                        <button type="button" class="sa-btn sa-btn--danger" @click="destroy(auto.id)">Delete</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api/client.js';

const items = ref([]);
const loading = ref(false);
const search = ref('');
const enabled = ref('');

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const params = {};
        if (search.value) params.search = search.value;
        if (enabled.value) params.enabled = enabled.value === 'true' ? 1 : 0;
        const response = await api.automations.list(params);
        items.value = response.data ?? [];
    } finally {
        loading.value = false;
    }
}

async function duplicate(id) {
    await api.automations.duplicate(id);
    await load();
}

async function destroy(id) {
    if (!window.confirm('Delete this automation? This cannot be undone.')) return;
    await api.automations.destroy(id);
    await load();
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
