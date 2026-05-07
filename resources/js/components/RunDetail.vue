<template>
    <div class="sa-run-detail">
        <header class="sa-run-detail__header">
            <a href="../runs" class="sa-run-detail__back">← All runs</a>
            <h1 v-if="run" class="sa-run-detail__title">Run #{{ run.id }}</h1>
        </header>

        <LoadingSpinner v-if="loading" label="Loading run…" />
        <ErrorMessage v-else-if="error" :message="error" level="error" title="Couldn't load run">
            <template #actions>
                <button type="button" class="sa-btn" @click="load">Retry</button>
            </template>
        </ErrorMessage>

        <div v-else-if="run" class="sa-run-detail__body">
            <div class="sa-run-detail__summary">
                <div>
                    <strong>Automation:</strong> {{ run.automation?.name ?? '—' }}
                </div>
                <div>
                    <strong>Status:</strong>
                    <span :class="['sa-pill', `sa-pill--${run.status}`]">{{ run.status }}</span>
                </div>
                <div><strong>Trigger:</strong> <code>{{ run.trigger_type ?? '—' }}</code></div>
                <div><strong>Started:</strong> {{ formatDate(run.started_at) }}</div>
                <div><strong>Finished:</strong> {{ formatDate(run.finished_at) }}</div>
                <div v-if="run.duration_ms != null"><strong>Duration:</strong> {{ run.duration_ms }} ms</div>
            </div>

            <p v-if="run.error_message" class="sa-run-detail__error">{{ run.error_message }}</p>

            <h2>Node runs</h2>
            <ol class="sa-runlog__nodes sa-runlog__nodes--full">
                <li
                    v-for="nodeRun in run.node_runs ?? []"
                    :key="nodeRun.id"
                    class="sa-runlog__node"
                    :class="`sa-runlog__node--${nodeRun.status}`"
                >
                    <header class="sa-runlog__node-header">
                        <span class="sa-runlog__node-key">{{ nodeRun.node_key }}</span>
                        <span class="sa-runlog__node-type">{{ nodeRun.node_type }}</span>
                        <span :class="`sa-runlog__status sa-runlog__status--${nodeRun.status}`">{{ nodeRun.status }}</span>
                        <span v-if="nodeRun.duration_ms != null">{{ nodeRun.duration_ms }} ms</span>
                    </header>
                    <details v-if="nodeRun.input">
                        <summary>Input</summary>
                        <pre class="sa-runlog__io">{{ stringify(nodeRun.input) }}</pre>
                    </details>
                    <details v-if="nodeRun.output">
                        <summary>Output</summary>
                        <pre class="sa-runlog__io">{{ stringify(nodeRun.output) }}</pre>
                    </details>
                    <p v-if="nodeRun.error_message" class="sa-runlog__node-error">{{ nodeRun.error_message }}</p>
                </li>
            </ol>

            <button type="button" class="sa-btn sa-btn--secondary" @click="retry">Retry run</button>
        </div>

        <Toast v-if="toastState.message" :key="toastState.seq" :message="toastState.message" :level="toastState.level" />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api/client.js';
import LoadingSpinner from './ui/LoadingSpinner.vue';
import ErrorMessage from './ui/ErrorMessage.vue';
import Toast from './ui/Toast.vue';
import { toast, useToastState } from '../composables/useToast.js';

const props = defineProps({
    run_id: { type: [String, Number], default: null },
});

const run = ref(null);
const loading = ref(false);
const error = ref(null);
const toastState = useToastState();

onMounted(load);

async function load() {
    if (!props.run_id) return;
    loading.value = true;
    error.value = null;
    try {
        run.value = await api.runs.get(props.run_id);
    } catch (e) {
        error.value = e?.response?.data?.message ?? 'Couldn\'t load run.';
    } finally {
        loading.value = false;
    }
}

async function retry() {
    try {
        await api.runs.retry(props.run_id);
        toast.success(`Re-queued run #${props.run_id}`);
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

function stringify(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}
</script>
