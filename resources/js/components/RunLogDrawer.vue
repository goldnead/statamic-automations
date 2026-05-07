<template>
    <aside class="sa-runlog" :class="{ 'sa-runlog--open': open }">
        <header class="sa-runlog__header">
            <h4 class="sa-runlog__title">Run log</h4>
            <button type="button" class="sa-runlog__close" @click="$emit('close')">×</button>
        </header>

        <div v-if="!run" class="sa-runlog__empty">No run yet — click Test to execute.</div>

        <div v-else class="sa-runlog__inner">
            <div class="sa-runlog__summary">
                <strong>Run #{{ run.run_id ?? run.id }}</strong>
                <span :class="`sa-runlog__status sa-runlog__status--${run.status}`">{{ run.status }}</span>
                <span v-if="run.duration_ms != null">{{ run.duration_ms }} ms</span>
            </div>
            <p v-if="run.error_message" class="sa-runlog__error">{{ run.error_message }}</p>

            <ol class="sa-runlog__nodes">
                <li
                    v-for="nodeRun in run.node_runs ?? []"
                    :key="nodeRun.id ?? nodeRun.node_key"
                    class="sa-runlog__node"
                    :class="`sa-runlog__node--${nodeRun.status}`"
                >
                    <header class="sa-runlog__node-header">
                        <span class="sa-runlog__node-key">{{ nodeRun.node_key }}</span>
                        <span class="sa-runlog__node-type">{{ nodeRun.node_type }}</span>
                        <span :class="`sa-runlog__status sa-runlog__status--${nodeRun.status}`">{{ nodeRun.status }}</span>
                    </header>
                    <pre v-if="nodeRun.output" class="sa-runlog__io">{{ stringify(nodeRun.output) }}</pre>
                    <p v-if="nodeRun.error_message" class="sa-runlog__node-error">{{ nodeRun.error_message }}</p>
                </li>
            </ol>
        </div>
    </aside>
</template>

<script setup>
defineProps({
    run: { type: Object, default: null },
    open: { type: Boolean, default: false },
});

defineEmits(['close']);

function stringify(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}
</script>
