<template>
    <div class="sa-list">
        <header class="sa-list__header">
            <div>
                <h1 class="sa-list__title">Automation Runs</h1>
                <p class="sa-list__subtitle">All triggered runs across enabled automations.</p>
            </div>
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

        <LoadingSpinner v-if="loading" label="Loading runs…" />
        <ErrorMessage v-else-if="error" :message="error" level="error" title="Couldn't load runs">
            <template #actions>
                <button type="button" class="sa-btn" @click="load">Retry</button>
            </template>
        </ErrorMessage>
        <EmptyState
            v-else-if="!items.length"
            title="No runs match the current filter"
            message="Trigger one of your automations or run a test from the builder."
        />
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
                        <button type="button" class="sa-btn sa-btn--ghost" @click="retry(run)">Retry</button>
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
const status = ref('');
const testFilter = ref('');
const toastState = useToastState();

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const params = {};
        if (status.value) params.status = status.value;
        if (testFilter.value) params.is_test = testFilter.value === 'true' ? 1 : 0;
        const response = await api.runs.list(params);
        items.value = response.data ?? [];
    } catch (e) {
        error.value = e?.response?.data?.message ?? 'Couldn\'t load runs.';
    } finally {
        loading.value = false;
    }
}

async function retry(run) {
    try {
        await api.runs.retry(run.id);
        toast.success(`Re-queued run #${run.id}`);
        await load();
    } catch (e) {
        toast.error(e?.response?.data?.message ?? 'Retry failed.');
    }
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
