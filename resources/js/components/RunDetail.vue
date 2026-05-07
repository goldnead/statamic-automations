<template>
    <div class="sa-run-detail">
        <header class="sa-run-detail__header">
            <a href="../runs" class="sa-run-detail__back">← All runs</a>
            <h1 v-if="run" class="sa-run-detail__title">Run #{{ run.id }}</h1>
        </header>

        <div v-if="loading" class="sa-list__loading">Loading…</div>

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
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api/client.js';

const props = defineProps({
    run_id: { type: [String, Number], default: null },
});

const run = ref(null);
const loading = ref(false);

onMounted(load);

async function load() {
    if (!props.run_id) return;
    loading.value = true;
    try {
        run.value = await api.runs.get(props.run_id);
    } finally {
        loading.value = false;
    }
}

async function retry() {
    await api.runs.retry(props.run_id);
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

function stringify(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}
</script>
