<template>
    <div class="sa-builder" :class="{ 'sa-builder--drawer-open': drawerOpen }">
        <header class="sa-builder__topbar">
            <div class="sa-builder__title">
                <input
                    v-model="automation.name"
                    type="text"
                    class="sa-builder__name-input"
                    :placeholder="'Untitled automation'"
                />
                <span class="sa-builder__handle">{{ automation.handle || 'no handle yet' }}</span>
            </div>
            <div class="sa-builder__topbar-actions">
                <label class="sa-builder__toggle">
                    <input type="checkbox" :checked="automation.enabled" @change="toggleEnabled" />
                    <span>Enabled</span>
                </label>
                <button class="sa-btn" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
                <button class="sa-btn sa-btn--secondary" @click="validate">Validate</button>
                <button class="sa-btn sa-btn--secondary" @click="testRun">Test</button>
                <button class="sa-btn sa-btn--ghost" @click="toggleDrawer">
                    {{ drawerOpen ? 'Hide log' : 'Run log' }}
                </button>
            </div>
        </header>

        <div class="sa-builder__main">
            <NodeLibrary :nodes="library" @add="addNodeFromLibrary" />

            <Canvas
                ref="canvasRef"
                :nodes="canvasNodes"
                :edges="canvasEdges"
                :selected-key="selectedNodeKey"
                :validation="validationByNode"
                @select="selectNode"
                @update-positions="updatePositions"
                @connect="connectNodes"
                @remove-edge="removeEdge"
                @remove-node="removeNode"
            />

            <ConfigPanel
                :node="selectedNode"
                :schema="schemaForSelected"
                :data-picker-source="dataPickerSource"
                @update-config="updateNodeConfig"
                @rename="renameNode"
            />
        </div>

        <ValidationDrawer v-if="issues.length" :issues="issues" />
        <RunLogDrawer v-if="drawerOpen" :run="lastRun" :open="drawerOpen" @close="drawerOpen = false" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api/client.js';
import { nodeKey } from '../utils/uuid.js';
import NodeLibrary from './NodeLibrary.vue';
import Canvas from './Canvas.vue';
import ConfigPanel from './ConfigPanel.vue';
import ValidationDrawer from './ValidationDrawer.vue';
import RunLogDrawer from './RunLogDrawer.vue';

const props = defineProps({
    automation_id: { type: [String, Number], default: null },
});

const automation = ref({
    id: null,
    name: '',
    handle: '',
    description: '',
    enabled: false,
    nodes: [],
    edges: [],
});
const library = ref({ triggers: [], logic: [], actions: [], integrations: {} });
const issues = ref([]);
const saving = ref(false);
const drawerOpen = ref(false);
const lastRun = ref(null);
const selectedNodeKey = ref(null);

const canvasNodes = computed(() => automation.value.nodes ?? []);
const canvasEdges = computed(() => automation.value.edges ?? []);
const selectedNode = computed(() =>
    canvasNodes.value.find((n) => n.node_key === selectedNodeKey.value) ?? null,
);

const schemaForSelected = computed(() => {
    if (!selectedNode.value) return null;
    return findSchema(selectedNode.value.type);
});

const dataPickerSource = computed(() => {
    const trigger = canvasNodes.value.find((n) => isTrigger(n.type));
    if (!trigger) return null;
    return trigger.type;
});

const validationByNode = computed(() => {
    const map = {};
    for (const issue of issues.value) {
        if (issue.node_key) {
            map[issue.node_key] = issue.level || 'error';
        }
    }
    return map;
});

onMounted(async () => {
    const lib = await api.nodes.index();
    library.value = { ...lib.data, integrations: lib.meta?.integrations ?? {} };

    if (props.automation_id) {
        automation.value = await api.automations.get(props.automation_id);
    }
});

function findSchema(type) {
    const all = [
        ...library.value.triggers,
        ...library.value.logic,
        ...library.value.actions,
    ];
    return all.find((n) => n.handle === type) ?? null;
}

function isTrigger(type) {
    return library.value.triggers.some((n) => n.handle === type);
}

function addNodeFromLibrary(libraryNode) {
    const key = nodeKey(libraryNode.handle.split('.').pop());
    automation.value.nodes.push({
        node_key: key,
        type: libraryNode.handle,
        label: libraryNode.label,
        position_x: 100 + automation.value.nodes.length * 60,
        position_y: 100 + automation.value.nodes.length * 40,
        config: defaultConfig(libraryNode),
        disabled: false,
    });
    selectedNodeKey.value = key;
}

function defaultConfig(libraryNode) {
    const out = {};
    for (const field of libraryNode.schema ?? []) {
        if (field.default !== undefined) {
            out[field.handle] = field.default;
        }
    }
    return out;
}

function selectNode(key) {
    selectedNodeKey.value = key;
}

function updateNodeConfig(payload) {
    const node = automation.value.nodes.find((n) => n.node_key === payload.node_key);
    if (!node) return;
    node.config = { ...node.config, ...payload.config };
}

function renameNode(payload) {
    const node = automation.value.nodes.find((n) => n.node_key === payload.old_key);
    if (!node) return;
    const newKey = payload.new_key;
    automation.value.edges.forEach((e) => {
        if (e.from_node_key === node.node_key) e.from_node_key = newKey;
        if (e.to_node_key === node.node_key) e.to_node_key = newKey;
    });
    node.node_key = newKey;
    selectedNodeKey.value = newKey;
}

function updatePositions(updates) {
    for (const upd of updates) {
        const node = automation.value.nodes.find((n) => n.node_key === upd.node_key);
        if (node) {
            node.position_x = upd.position_x;
            node.position_y = upd.position_y;
        }
    }
}

function connectNodes({ from_node_key, from_output, to_node_key }) {
    const exists = automation.value.edges.some(
        (e) => e.from_node_key === from_node_key && e.from_output === (from_output || 'default') && e.to_node_key === to_node_key,
    );
    if (exists) return;
    automation.value.edges.push({
        from_node_key,
        from_output: from_output || 'default',
        to_node_key,
        to_input: 'default',
    });
}

function removeEdge({ from_node_key, from_output, to_node_key }) {
    automation.value.edges = automation.value.edges.filter(
        (e) => !(e.from_node_key === from_node_key && e.from_output === from_output && e.to_node_key === to_node_key),
    );
}

function removeNode(key) {
    automation.value.nodes = automation.value.nodes.filter((n) => n.node_key !== key);
    automation.value.edges = automation.value.edges.filter(
        (e) => e.from_node_key !== key && e.to_node_key !== key,
    );
    if (selectedNodeKey.value === key) selectedNodeKey.value = null;
}

async function save() {
    saving.value = true;
    try {
        const payload = {
            name: automation.value.name,
            description: automation.value.description,
            nodes: automation.value.nodes,
            edges: automation.value.edges,
        };
        if (automation.value.id) {
            const updated = await api.automations.update(automation.value.id, payload);
            automation.value = updated;
        } else {
            const created = await api.automations.create(payload);
            automation.value = created;
            // Update URL so refresh keeps the same automation.
            const newUrl = window.location.pathname.replace(/\/create$/, `/${created.id}`);
            window.history.replaceState({}, '', newUrl);
        }
    } finally {
        saving.value = false;
    }
}

async function validate() {
    if (!automation.value.id) {
        issues.value = [{ level: 'warning', code: 'unsaved', message: 'Save the automation first to validate.' }];
        return;
    }
    const result = await api.automations.validate(automation.value.id);
    issues.value = result.issues ?? [];
}

async function testRun() {
    if (!automation.value.id) {
        issues.value = [{ level: 'warning', code: 'unsaved', message: 'Save the automation first to run a test.' }];
        return;
    }
    const result = await api.automations.test(automation.value.id, {});
    lastRun.value = result;
    drawerOpen.value = true;
}

async function toggleEnabled(event) {
    if (!automation.value.id) return;
    if (event.target.checked) {
        const result = await api.automations.enable(automation.value.id);
        if (result.ok) {
            automation.value.enabled = true;
        } else {
            issues.value = result.issues ?? [];
            event.target.checked = false;
        }
    } else {
        await api.automations.disable(automation.value.id);
        automation.value.enabled = false;
    }
}

function toggleDrawer() {
    drawerOpen.value = !drawerOpen.value;
}
</script>
