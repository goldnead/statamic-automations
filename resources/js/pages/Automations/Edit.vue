<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Switch,
    Stack,
    StackHeader,
    StackContent,
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
import { useHistory } from '../../composables/useHistory.js';

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

// Undo/redo over the graph (nodes + edges). Each mutation below calls
// `history.record()` after applying its change; drags commit once on drop.
const history = useHistory({
    getState: () => ({
        nodes: automation.value.nodes,
        edges: automation.value.edges,
    }),
    setState: (state) => {
        automation.value = {
            ...automation.value,
            nodes: state.nodes,
            edges: state.edges,
        };
        // Drop a selection that no longer exists after the restore.
        if (
            selectedNodeKey.value &&
            !state.nodes.some((n) => n.node_key === selectedNodeKey.value)
        ) {
            selectedNodeKey.value = null;
        }
    },
});

function undo() {
    history.undo();
}

function redo() {
    history.redo();
}

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
    history.record();
}

// Records positions after a drag ends (Canvas emits `positions-committed`).
function commitPositions() {
    history.record();
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
    history.record();
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
    history.record();
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
    history.record();
}

function updateNodeConfig(config) {
    if (!selectedNodeKey.value) return;
    automation.value.nodes = automation.value.nodes.map((n) =>
        n.node_key === selectedNodeKey.value ? { ...n, config } : n,
    );
    history.record();
}

function updateNodeLabel(label) {
    if (!selectedNodeKey.value) return;
    automation.value.nodes = automation.value.nodes.map((n) =>
        n.node_key === selectedNodeKey.value ? { ...n, label } : n,
    );
    history.record();
}

function duplicateNode(nodeKey) {
    const src = automation.value.nodes.find((n) => n.node_key === nodeKey);
    if (!src) return;
    const newKey = `${src.type.replace(/\W/g, '_')}_${Math.random().toString(36).slice(2, 6)}`;
    automation.value.nodes = [
        ...automation.value.nodes,
        {
            ...src,
            node_key: newKey,
            label: src.label ? `${src.label} (${__('copy')})` : src.label,
            position_x: (src.position_x ?? 0) + 40,
            position_y: (src.position_y ?? 0) + 40,
            config: JSON.parse(JSON.stringify(src.config ?? {})),
        },
    ];
    selectedNodeKey.value = newKey;
    history.record();
}

function toggleNodeDisabled(nodeKey) {
    automation.value.nodes = automation.value.nodes.map((n) =>
        n.node_key === nodeKey ? { ...n, disabled: !n.disabled } : n,
    );
    history.record();
}

// "Rename" from a node's context menu just focuses it — the editable Name lives
// in the Properties panel's Detail section (no native prompt dialogs).
function renameNode(nodeKey) {
    selectedNodeKey.value = nodeKey;
}

// ---------- Keyboard shortcuts ----------
// Cmd/Ctrl+Z → undo, Cmd/Ctrl+Shift+Z (or Ctrl+Y) → redo. Skip when the user is
// typing in a field so native text undo keeps working there.
function onKeydown(e) {
    const mod = e.metaKey || e.ctrlKey;
    if (!mod) return;

    const el = e.target;
    const tag = el?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el?.isContentEditable) {
        return;
    }

    const key = e.key.toLowerCase();
    if (key === 'z' && !e.shiftKey) {
        e.preventDefault();
        undo();
    } else if ((key === 'z' && e.shiftKey) || key === 'y') {
        e.preventDefault();
        redo();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <!-- Single-root builder page.
     *
     * Statamic's CP is an Inertia SPA with a *persistent* layout: every page is
     * rendered into one shared `<slot>` inside `#content-card`. When a page is a
     * multi-root fragment (`<Head>` + wrapper + `<Stack>`), the leaving page and
     * the entering page briefly share that slot, and because the builder breaks
     * out of the card and mounts an absolutely-positioned Vue Flow canvas, the
     * outgoing overview listing could stay painted over the canvas until a reload.
     *
     * Fixes:
     *  - ONE root element, so the slot has an unambiguous node to swap on nav.
     *  - `relative isolate` + an opaque `bg-body-bg`, so the builder is its own
     *    stacking context and opaque layer inside the content card — no stale
     *    layer from the previous page can composite over it.
     *  - `<Head>` and the run-log `<Stack>` are nested here; both render
     *    out-of-flow (head manager / teleport) so nesting is safe.
     *
     * The builder is a canvas tool, so it still breaks out of the CP content
     * card's horizontal padding (px-12 at lg) to use the full width. -->
    <div class="relative isolate lg:-mx-12 bg-body-bg" data-max-width-wrapper>
        <Head :title="[title, __('Statamic Automations')]" />

        <Header :title="title">
            <template #title>
                <div class="flex items-center gap-2">
                    <Icon name="workflow" class="size-5 text-gray-500" />
                    <span class="text-[15px] text-gray-400 dark:text-gray-500 font-medium">{{ __('Automations') }}</span>
                    <span class="text-gray-300 dark:text-gray-600">/</span>
                    <input
                        v-model="automation.name"
                        type="text"
                        class="bg-transparent border-none focus:outline-none focus:ring-0 text-[25px] font-medium antialiased min-w-[240px] text-gray-900 dark:text-gray-100"
                        :placeholder="__('Untitled automation')"
                    />
                    <Badge
                        :color="automation.enabled ? 'green' : 'amber'"
                        :text="automation.enabled ? __('Active') : __('Draft')"
                        pill
                    />
                </div>
            </template>

            <div class="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-700 mr-1">
                <Button
                    variant="ghost"
                    size="sm"
                    icon-only
                    icon="arrow-left"
                    :aria-label="__('Undo')"
                    :disabled="!history.canUndo.value || !canEdit"
                    @click="undo"
                />
                <Button
                    variant="ghost"
                    size="sm"
                    icon-only
                    icon="arrow-right"
                    :aria-label="__('Redo')"
                    :disabled="!history.canRedo.value || !canEdit"
                    @click="redo"
                />
            </div>

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

        <div class="grid grid-cols-[260px_1fr_360px] rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden h-[calc(100vh-220px)] min-h-[500px]">
            <div class="overflow-y-auto border-r border-gray-200 dark:border-gray-800">
                <NodeLibrary :library="library" @add="addNode" />
            </div>

            <div class="sa-canvas-frame">
                <Canvas
                    :nodes="automation.nodes"
                    :edges="automation.edges"
                    :selected-key="selectedNodeKey"
                    :validation="validationByNode"
                    :library="library"
                    @select="selectedNodeKey = $event"
                    @update-positions="updateNodePositions"
                    @positions-committed="commitPositions"
                    @connect="connect"
                    @remove-edge="removeEdge"
                    @remove-node="removeNode"
                    @rename-node="renameNode"
                    @duplicate-node="duplicateNode"
                    @toggle-node-disabled="toggleNodeDisabled"
                />
            </div>

            <div class="overflow-hidden border-l border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
                <ConfigPanel
                    :node="selectedNode"
                    :library="library"
                    :trigger-output-schema="triggerOutputSchema"
                    :api-base="apiBase"
                    @update:config="updateNodeConfig"
                    @update:label="updateNodeLabel"
                    @duplicate="duplicateNode(selectedNodeKey)"
                    @delete="removeNode(selectedNodeKey)"
                />
            </div>
        </div>

        <Stack v-if="drawerOpen" :name="'sa-runlog'" @closed="drawerOpen = false">
            <StackHeader :heading="__('Run log')" />
            <StackContent>
                <RunLogPanel :run="lastRun" />
            </StackContent>
        </Stack>
    </div>
</template>
