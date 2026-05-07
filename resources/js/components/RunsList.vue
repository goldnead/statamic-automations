<template>
    <div class="sa-list">
        <header class="sa-list__header">
            <h1 class="sa-list__title">Automation Runs</h1>
        </header>

        <div class="sa-list__filters">
            <select class="sa-list__filter" v-model="status" @change="load">
                <option value="">Any status</option>
                <option value="success">Success</option>
                <option value="running">Running</option>
                <option value="waiting">Waiting</option>
                <option value="stopped">Stopped</option>
                <option value="failed">Failed</option>
            </select>
            <select class="sa-list__filter" v-model="testFilter" @change="load">
                <option value="">Live + test</option>
                <option value="true">Test runs only</option>
                <option value="false">Live runs only</option>
            </select>
        </div>

        <div v-if="loading" class="sa-list__loading">Loading…</div>
        <div v-else-if="!items.length" class="sa-list__empty">No runs match the current filter.</div>
        <table v-else class="sa-list__table">
            <thead>
                <tr>
                    <th>Run</th>
                    <th>Automation</th>
                    <th>Trigger</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th>Duration</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in items" :key="run.id">
                    <td><a :href="`runs/${run.id}`">#{{ run.id }}</a></td>
                    <td>{{ run.automation?.name ?? '—' }}</td>
                    <td><code>{{ run.trigger_type ?? '—' }}</code></td>
                    <td>
                        <span :class="['sa-pill', `sa-pill--${run.status}`]">{{ run.status }}</span>
                        <span v-if="run.is_test" class="sa-pill sa-pill--gray">test</span>
                    </td>
                    <td>{{ formatDate(run.started_at ?? run.created_at) }}</td>
                    <td>{{ run.duration_ms != null ? `${run.duration_ms} ms` : '—' }}</td>
                    <td class="sa-list__actions">
                        <button type="button" class="sa-btn sa-btn--ghost" @click="retry(run.id)">Retry</button>
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
const status = ref('');
const testFilter = ref('');

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const params = {};
        if (status.value) params.status = status.value;
        if (testFilter.value) params.is_test = testFilter.value === 'true' ? 1 : 0;
        const response = await api.runs.list(params);
        items.value = response.data ?? [];
    } finally {
        loading.value = false;
    }
}

async function retry(id) {
    await api.runs.retry(id);
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
