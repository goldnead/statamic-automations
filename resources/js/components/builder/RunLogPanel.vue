<template>
    <div class="p-4 space-y-3">
        <div v-if="!run" class="text-sm text-gray-500 text-center">
            {{ __('No run yet — click Test to execute.') }}
        </div>

        <template v-else>
            <header class="flex items-center gap-2 flex-wrap">
                <strong class="text-sm">{{ __('Run #:id', { id: run.run_id }) }}</strong>
                <Badge :color="statusColor(run.status)" :text="run.status" />
                <span v-if="run.duration_ms != null" class="text-2xs text-gray-500">
                    {{ run.duration_ms }} ms
                </span>
            </header>

            <Alert v-if="run.error_message" variant="error">
                {{ run.error_message }}
            </Alert>

            <ol class="space-y-2">
                <li
                    v-for="(nodeRun, i) in run.node_runs ?? []"
                    :key="i"
                    class="rounded border border-gray-200 dark:border-gray-700 p-2 bg-gray-50 dark:bg-gray-900"
                >
                    <header class="flex items-center gap-2 text-xs">
                        <code class="font-mono font-semibold">{{ nodeRun.node_key }}</code>
                        <span class="text-gray-500">{{ nodeRun.node_type }}</span>
                        <Badge :color="statusColor(nodeRun.status)" :text="nodeRun.status" />
                    </header>
                    <pre v-if="nodeRun.output" class="text-2xs font-mono mt-2 p-2 rounded bg-white dark:bg-gray-800 overflow-x-auto max-h-40">{{ stringify(nodeRun.output) }}</pre>
                    <p v-if="nodeRun.error_message" class="text-xs text-red-600 dark:text-red-400 mt-1">
                        {{ nodeRun.error_message }}
                    </p>
                </li>
            </ol>
        </template>
    </div>
</template>

<script setup>
import { Badge, Alert } from '@statamic/cms/ui';

defineProps({
    run: { type: Object, default: null },
});

function statusColor(s) {
    return {
        success: 'green',
        failed: 'red',
        stopped: 'amber',
        running: 'blue',
        skipped: 'gray',
    }[s] ?? 'gray';
}

function stringify(value) {
    try { return JSON.stringify(value, null, 2); }
    catch { return String(value); }
}
</script>
