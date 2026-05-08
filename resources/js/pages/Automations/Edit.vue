<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Switch,
    Stack,
    StackHeader,
    StackContent,
    Panel,
    Field,
    Input,
    Alert,
    Badge,
    Icon,
} from '@statamic/cms/ui';
import axios from 'axios';

import Canvas from '../../components/builder/Canvas.vue';
import NodeLibrary from '../../components/builder/NodeLibrary.vue';
import ConfigPanel from '../../components/builder/ConfigPanel.vue';
import RunLogPanel from '../../components/builder/RunLogPanel.vue';
import { useAutosave } from '../../composables/useAutosave.js';

const props = defineProps({
    mode: { type: String, required: true },           // 'create' | 'edit'
    title: { type: String, required: true },
    automation: { type: Object, required: true },
    library: { type: Object, required: true },
    apiBase: { type: String, required: true },
    indexUrl: { type: String, required: true },
    runsUrl: { type: String, default: null },
    canEdit: { type: Boolean, default: true },
    canEnable: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    canTest: { type: Boolean, default: true },
});

// Local mutable state (we keep the user's edits client-side until Save).
const automation = ref({ ...props.automation });
const issues = ref([]);
const saving = ref(false);
const drawerOpen = ref(false);
const lastRun = ref(null);
const selectedNodeKey = ref(null);

const selectedNode = computed(() =>
    automation.value.nodes.find((n) => n.node_key === selectedNodeKey.value) ?? null,
);

const triggerOutputSchema = computed(() => {
    const trigger = automation.value.nodes.find((n) =>
        props.library.triggers.some((t) => t.handle === n.type),
    );
    if (!trigger) return null;
    const meta = props.library.triggers.find((t) => t.handle === trigger.type);
    return meta?.output_schema ?? null;
});

const validationByNode = computed(() => {
    const map = {};
    for (const issue of issues.value) {
        if (issue.node_key) {
            map[issue.node_key] = issue.level;
        }
    }
    return map;
});

const autosave = useAutosave({
    source: () => ({
        name: automation.value.name,
        description: automation.value.description,
        nodes: automation.value.nodes,
        edges: automation.value.edges,
    }),
    saver: async () => {
        if (!automation.value.id) return;
        await save({ silent: true });
    },
    debounceMs: 2000,
    defaultEnabled: false,
});

function notify(level, message) {
    if (window.Statamic?.$toast?.[level]) {
        window.Statamic.$toast[level](message);
    } else {
        // Fallback
        // eslint-disable-next-line no-console
        console.log(`[${level}]`, message);
    }
}

async function save({ silent = false } = {}) {
    saving.value = true;
    try {
        const payload = {
            name: automation.value.name,
            description: automation.value.description,
            nodes: automation.value.nodes,
            edges: automation.value.edges,
        };
        if (automation.value.id) {
            const { data } = await axios.patch(
                `${props.apiBase}/automations/${automation.value.id}`,
                payload,
            );
            automation.value = { ...automation.value, ...(data?.data ?? data) };
            if (!silent) notify('success', __('Automation saved.'));
        } else {
            const { data } = await axios.post(`${props.apiBase}/automations`, payload);
            const created = data?.data ?? data;
            automation.value = { ...automation.value, id: created.id, handle: created.handle };
            // Move from /create to /edit URL so refreshes keep the same automation.
            router.visit(props.indexUrl + '/automations/' + created.id + '/edit', {
                replace: true,
                preserveState: true,
            });
            if (!silent) notify('success', __('Automation created.'));
        }
    } catch (e) {
        if (!silent) notify('error', e?.response?.data?.message ?? __('Save failed.'));
        throw e;
    } finally {
        saving.value = false;
    }
}

async function validate() {
    if (!automation.value.id) {
        notify('warning', __('Save the automation first to validate.'));
        return;
    }
    try {
        const { data } = await axios.post(
            `${props.apiBase}/automations/${automation.value.id}/validate`,
        );
        issues.value = data.issues ?? [];
        if (data.valid) {
            notify('success', __('Automation is valid.'));
        } else {
            notify('warning', __(':n issue(s) found.', { n: issues.value.length }));
        }
    } catch {
        notify('error', __('Validation failed.'));
    }
}

async function testRun() {
    if (!automation.value.id) {
        notify('warning', __('Save the automation first.'));
        return;
    }
    try {
        const { data } = await axios.post(
            `${props.apiBase}/automations/${automation.value.id}/test`,
            { context: {} },
        );
        lastRun.value = data;
        drawerOpen.value = true;
        if (data.status === 'success') {
            notify('success', __('Test run completed.'));
        } else if (data.status === 'failed') {
            notify('error', data.error_message ?? __('Test run failed.'));
        } else {
            notify('info', __('Run finished with status: :status', { status: data.status }));
        }
    } catch (e) {
        notify('error', e?.response?.data?.message ?? __('Test run failed.'));
    }
}

async function toggleEnabled() {
    if (!automation.value.id) {
        notify('warning', __('Save the automation first.'));
        return;
    }
    const next = !automation.value.enabled;
    try {
        const url = `${props.apiBase}/automations/${automation.value.id}/${next ? 'enable' : 'disable'}`;
        const { data } = await axios.post(url);
        if (data?.ok === false) {
            issues.value = data.issues ?? [];
            notify('error', data.message ?? __('Could not enable.'));
        } else {
            automation.value.enabled = next;
            notify(next ? 'success' : 'info', next ? __('Enabled.') : __('Disabled.'));
        }
    } catch (e) {
        notify('error', e?.response?.data?.message ?? __('Toggle failed.'));
    }
}

async function exportJson() {
    if (!automation.value.id) return;
    try {
        const { data } = await axios.get(
            `${props.apiBase}/automations/${automation.value.id}/export`,
        );
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${automation.value.handle || 'automation'}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        notify('success', __('Exported.'));
    } catch {
        notify('error', __('Export failed.'));
    }
}

// ---------- Canvas mutations ----------

function addNode(handle) {
    const meta = findHandleMeta(handle);
    if (!meta) return;

    const nodeKey = `${meta.handle.replace(/\W/g, '_')}_${Math.random().toString(36).slice(2, 6)}`;
    automation.value.nodes = [
        ...automation.value.nodes,
        {
            node_key: nodeKey,
            type: meta.handle,
            label: meta.label,
            position_x: 100 + Math.random() * 250,
            position_y: 100 + Math.random() * 250,
            config: {},
            disabled: false,
        },
    ];
    selectedNodeKey.value = nodeKey;
}

function findHandleMeta(handle) {
    return [
        ...(props.library.triggers ?? []),
        ...(props.library.logic ?? []),
        ...(props.library.actions ?? []),
    ].find((m) => m.handle === handle);
}

function updateNodePositions(positions) {
    automation.value.nodes = automation.value.nodes.map((n) => {
        const next = positions.find((p) => p.node_key === n.node_key);
        return next ? { ...n, position_x: next.position_x, position_y: next.position_y } : n;
    });
}

function removeNode(nodeKey) {
    automation.value.nodes = automation.value.nodes.filter((n) => n.node_key !== nodeKey);
    automation.value.edges = automation.value.edges.filter(
        (e) => e.from_node_key !== nodeKey && e.to_node_key !== nodeKey,
    );
    if (selectedNodeKey.value === nodeKey) selectedNodeKey.value = null;
}

function connect(edge) {
    if (
        automation.value.edges.some(
            (e) =>
                e.from_node_key === edge.from_node_key &&
                e.from_output === (edge.from_output ?? 'default') &&
                e.to_node_key === edge.to_node_key,
        )
    ) {
        return;
    }
    automation.value.edges = [
        ...automation.value.edges,
        { from_output: 'default', to_input: 'default', ...edge },
    ];
}

function removeEdge(edge) {
    automation.value.edges = automation.value.edges.filter(
        (e) =>
            !(
                e.from_node_key === edge.from_node_key &&
                e.from_output === edge.from_output &&
                e.to_node_key === edge.to_node_key
            ),
    );
}

function updateNodeConfig(config) {
    if (!selectedNodeKey.value) return;
    automation.value.nodes = automation.value.nodes.map((n) =>
        n.node_key === selectedNodeKey.value ? { ...n, config } : n,
    );
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-7xl mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="hammer">
            <template #title>
                <div class="flex items-center gap-2">
                    <Icon name="hammer" class="size-5 text-gray-500" />
                    <input
                        v-model="automation.name"
                        type="text"
                        class="bg-transparent border-none focus:outline-none focus:ring-0 text-[25px] font-medium antialiased min-w-[280px] text-gray-900 dark:text-gray-100"
                        :placeholder="__('Untitled automation')"
                    />
                </div>
            </template>

            <Button :text="__('Validate')" variant="ghost" @click="validate" />
            <Button :text="__('Test')" variant="ghost" :disabled="!canTest" @click="testRun" />
            <Button :text="__('Export')" variant="ghost" :disabled="!automation.id" @click="exportJson" />

            <div class="flex items-center gap-2 px-2 border-l border-gray-200 dark:border-gray-700 ml-2">
                <span class="text-xs text-gray-600 dark:text-gray-400">{{ __('Enabled') }}</span>
                <Switch :model-value="automation.enabled" :disabled="!canEnable || !automation.id" @update:model-value="toggleEnabled" />
            </div>

            <Button
                :text="saving ? __('Saving…') : __('Save')"
                variant="primary"
                :disabled="saving || !canEdit"
                @click="save()"
            />
        </Header>

        <Alert v-if="issues.length" variant="warning" class="mb-4">
            <strong>{{ __(':n issue(s) found:', { n: issues.length }) }}</strong>
            <ul class="mt-1 ml-4 list-disc text-sm">
                <li v-for="(issue, i) in issues" :key="i">
                    <Badge :color="issue.level === 'error' ? 'red' : 'amber'" :text="issue.level" class="mr-1" />
                    {{ issue.message }}
                    <code v-if="issue.node_key" class="text-xs ml-1">{{ issue.node_key }}</code>
                </li>
            </ul>
        </Alert>

        <div class="grid grid-cols-[260px_1fr_360px] gap-px bg-gray-200 dark:bg-gray-800 rounded-md overflow-hidden h-[calc(100vh-220px)] min-h-[500px]">
            <Panel class="!rounded-none overflow-y-auto bg-white dark:bg-gray-900">
                <NodeLibrary :library="library" @add="addNode" />
            </Panel>

            <div class="bg-gray-50 dark:bg-gray-900 sa-canvas-frame">
                <Canvas
                    :nodes="automation.nodes"
                    :edges="automation.edges"
                    :selected-key="selectedNodeKey"
                    :validation="validationByNode"
                    :library="library"
                    @select="selectedNodeKey = $event"
                    @update-positions="updateNodePositions"
                    @connect="connect"
                    @remove-edge="removeEdge"
                    @remove-node="removeNode"
                />
            </div>

            <Panel class="!rounded-none overflow-y-auto bg-white dark:bg-gray-900">
                <ConfigPanel
                    :node="selectedNode"
                    :library="library"
                    :trigger-output-schema="triggerOutputSchema"
                    :api-base="apiBase"
                    @update:config="updateNodeConfig"
                />
            </Panel>
        </div>
    </div>

    <Stack v-if="drawerOpen" :name="'sa-runlog'" @closed="drawerOpen = false">
        <StackHeader :heading="__('Run log')" />
        <StackContent>
            <RunLogPanel :run="lastRun" />
        </StackContent>
    </Stack>
</template>
