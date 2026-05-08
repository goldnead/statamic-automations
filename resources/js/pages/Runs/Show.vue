<script setup>
import { ref } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Panel,
    Badge,
    CodeEditor,
    Alert,
    Icon,
} from '@statamic/cms/ui';
import axios from 'axios';

const props = defineProps({
    title: { type: String, required: true },
    run: { type: Object, required: true },
    indexUrl: { type: String, required: true },
    retryUrl: { type: String, required: true },
    canRetry: { type: Boolean, default: false },
});

const retryingNodeId = ref(null);

function statusColor(s) {
    return {
        success: 'green',
        failed: 'red',
        stopped: 'amber',
        running: 'blue',
        queued: 'gray',
        waiting: 'blue',
        skipped: 'gray',
    }[s] ?? 'gray';
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

async function retry() {
    try {
        await axios.post(props.retryUrl);
        window.Statamic?.$toast?.success?.(__('Re-queued.'));
    } catch (e) {
        window.Statamic?.$toast?.error?.(e?.response?.data?.message ?? __('Retry failed.'));
    }
}

async function retryNode(nodeRun) {
    retryingNodeId.value = nodeRun.id;
    try {
        await axios.post(
            props.retryUrl.replace(/\/runs\/.+\/retry$/, `/node-runs/${nodeRun.id}/retry`),
        );
        window.Statamic?.$toast?.success?.(__('Re-queued from :node', { node: nodeRun.node_key }));
    } catch (e) {
        window.Statamic?.$toast?.error?.(e?.response?.data?.message ?? __('Partial retry failed.'));
    } finally {
        retryingNodeId.value = null;
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

<template>
    <Head :title="[title, __('Runs'), __('Statamic Automations')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="paper-airplane">
            <Link :href="indexUrl" class="text-sm text-gray-500 hover:text-blue-500 mr-2">
                ← {{ __('All runs') }}
            </Link>
            <Button
                v-if="canRetry"
                :text="__('Retry run')"
                variant="ghost"
                @click="retry"
            />
        </Header>

        <Panel :heading="__('Run summary')">
            <dl class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Status') }}</dt>
                    <dd><Badge :color="statusColor(run.status)" :text="run.status" /></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Trigger') }}</dt>
                    <dd><code class="text-xs">{{ run.trigger_type ?? '—' }}</code></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Duration') }}</dt>
                    <dd>{{ run.duration_ms != null ? `${run.duration_ms} ms` : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Started') }}</dt>
                    <dd>{{ formatDate(run.started_at) }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Finished') }}</dt>
                    <dd>{{ formatDate(run.finished_at) }}</dd>
                </div>
                <div v-if="run.automation">
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Automation') }}</dt>
                    <dd><Link :href="run.automation.edit_url">{{ run.automation.name }}</Link></dd>
                </div>
            </dl>
        </Panel>

        <Alert v-if="run.error_message" variant="error" class="my-4">
            <strong class="block mb-1">{{ __('Run error') }}</strong>
            <pre class="text-sm whitespace-pre-wrap">{{ run.error_message }}</pre>
        </Alert>

        <Panel :heading="__('Initial context')" class="mt-4">
            <CodeEditor
                v-if="run.context"
                :model-value="stringify(run.context)"
                mode="json"
                read-only
                class="text-xs"
            />
            <p v-else class="text-sm text-gray-500">{{ __('No context recorded.') }}</p>
        </Panel>

        <Panel :heading="__('Node runs')" class="mt-4">
            <ol class="space-y-3">
                <li
                    v-for="nodeRun in run.node_runs"
                    :key="nodeRun.id"
                    class="rounded border border-gray-200 dark:border-gray-700 p-3"
                    :class="`sa-node-run--${nodeRun.status}`"
                >
                    <header class="flex items-center gap-2 mb-2">
                        <code class="text-xs font-mono font-semibold">{{ nodeRun.node_key }}</code>
                        <span class="text-xs text-gray-500">{{ nodeRun.node_type }}</span>
                        <Badge :color="statusColor(nodeRun.status)" :text="nodeRun.status" />
                        <span v-if="nodeRun.duration_ms != null" class="text-2xs text-gray-500 ml-auto">
                            {{ nodeRun.duration_ms }} ms
                        </span>
                        <Button
                            v-if="canRetry"
                            :text="retryingNodeId === nodeRun.id ? __('Queued…') : __('Retry from here')"
                            variant="ghost"
                            size="sm"
                            :disabled="retryingNodeId === nodeRun.id"
                            @click="retryNode(nodeRun)"
                        />
                    </header>
                    <details v-if="nodeRun.input" class="text-xs">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ __('Input') }}</summary>
                        <CodeEditor
                            :model-value="stringify(nodeRun.input)"
                            mode="json"
                            read-only
                            class="mt-2"
                        />
                    </details>
                    <details v-if="nodeRun.output" class="text-xs mt-1">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ __('Output') }}</summary>
                        <CodeEditor
                            :model-value="stringify(nodeRun.output)"
                            mode="json"
                            read-only
                            class="mt-2"
                        />
                    </details>
                    <p v-if="nodeRun.error_message" class="text-xs text-red-600 dark:text-red-400 mt-2">
                        {{ nodeRun.error_message }}
                    </p>
                </li>
            </ol>
            <p v-if="run.node_runs.length === 0" class="text-sm text-gray-500">
                {{ __('No nodes recorded for this run.') }}
            </p>
        </Panel>
    </div>
</template>
