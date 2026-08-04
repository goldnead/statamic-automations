<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    Stack,
    StackHeader,
    StackContent,
    Field,
    Input,
    Alert,
    Badge,
    ConfirmationModal,
    Icon,
    ToggleGroup,
    ToggleItem,
} from '@statamic/cms/ui';
import axios from 'axios';

import Canvas from '../../components/builder/Canvas.vue';
import NodeLibrary from '../../components/builder/NodeLibrary.vue';
import ConfigPanel from '../../components/builder/ConfigPanel.vue';
import RunLogPanel from '../../components/builder/RunLogPanel.vue';
import MailListPanel from '../../components/mails/MailListPanel.vue';
import { useAutosave } from '../../composables/useAutosave.js';
import { useHistory } from '../../composables/useHistory.js';
import { useGraphMutations } from '../../composables/useGraphMutations.js';
import { pendingTargetIsValid } from '../../composables/useFlowGuards.js';
import { computeNodeIssues, missingRequiredHandles } from '../../composables/useNodeValidation.js';
import { setNodeOutputSpecs } from '../../composables/useNodeOutputs.js';
import { errorBag, firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    mode: { type: String, required: true },           // 'create' | 'edit'
    title: { type: String, required: true },
    automation: { type: Object, required: true },
    library: { type: Object, required: true },
    apiBase: { type: String, required: true },
    indexUrl: { type: String, required: true },
    runsUrl: { type: String, default: null },
    // The same automation, read as the mails it sends. Always present on an
    // edit page: the list is shown for every automation and only its EDITING is
    // bound to the flow being a straight line (Sequence\LinearityRule).
    mailList: { type: Object, default: null },
    mailListUrl: { type: String, default: null },
    mailTypes: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
    canEdit: { type: Boolean, default: true },
    canEnable: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    canTest: { type: Boolean, default: true },
});

// Which output handles each node type has is declared by the node in PHP and
// travels in this payload (`library[*][].outputs`). Registering it here, at
// the top of setup, is what makes the canvas stop guessing: the layout, the
// handle dots, the "+" adders and every edge-writing mutation resolve it from
// the node's own declaration. Before the first render, because NodeCard reads
// it while rendering.
setNodeOutputSpecs(props.library);
watch(() => props.library, (library) => setNodeOutputSpecs(library));

// Local mutable state (we keep the user's edits client-side until Save).
const automation = ref({ ...props.automation });
const issues = ref([]);
const saving = ref(false);
const drawerOpen = ref(false);
const lastRun = ref(null);
const selectedNodeKey = ref(null);

// Left NodeLibrary sidebar: default shown/wide; collapses to a slim rail via
// the header chevron (see fix-picker-sidebar-brief.md § C3).
const showLibrary = ref(true);

// Two readings of one automation: the canvas, and the list of mails it sends.
// A second *view*, not a second page — the mail list is the same graph read
// differently, and putting it behind its own URL would invite the two to drift.
const view = ref('flow');

function setView(next) {
    // reka-ui's toggle group hands back `null` when the active item is pressed
    // again. There is no "neither" state here.
    if (next) view.value = next;
}

// The stored graph, as a string, so "has the canvas been edited since the last
// save" is one comparison. An edit made from the mail list is written straight
// to the database by ChainEditor; doing that while unsaved node edits sit on
// screen would let the next Save overwrite the reorder with a stale graph. The
// list refuses to edit while this says dirty, and says why.
function graphSignature(graph) {
    return JSON.stringify({ nodes: graph?.nodes ?? [], edges: graph?.edges ?? [] });
}

const savedGraph = ref(graphSignature(props.automation));
const graphDirty = computed(() => graphSignature(automation.value) !== savedGraph.value);

// Fullscreen editor: the canvas + side panels must fill the CP content area
// down to the bottom edge. Rather than hardcode `100vh - N` (which drifts when
// the CP chrome, the addon header or the issues Alert change height), we
// measure the editor element's live top offset and subtract it (plus a small
// bottom gutter) from the viewport height on mount, on resize, and whenever
// something above it toggles.
const editorEl = ref(null);
const editorHeight = ref('600px');
const BOTTOM_GUTTER = 24; // breathing room under the editor, in px

function updateEditorHeight() {
    const el = editorEl.value;
    if (!el) return;
    // Hidden behind the list view, its top offset reads 0 and the measurement
    // would be nonsense. The switch back schedules a fresh one.
    if (view.value !== 'flow' && showMailList.value) return;
    const top = el.getBoundingClientRect().top;
    const available = window.innerHeight - top - BOTTOM_GUTTER;
    editorHeight.value = `${Math.max(480, Math.round(available))}px`;
}

function scheduleHeightUpdate() {
    nextTick(() => requestAnimationFrame(updateEditorHeight));
}

// "Pick mode" pending-insertion target (§ C1). Armed by clicking a canvas
// "+" (AdderNode/InsertableEdge, via Canvas's `saStartPick`/`saPendingTarget`
// injection) or a trigger node's "Replace trigger" action; disarmed by
// picking a node, clicking the same "+"/action again, pressing Escape, or
// the sidebar's Cancel button. Shape:
//   { kind: 'append', fromNodeKey: string|null, output: string }
//   { kind: 'insert', edge: { from_node_key, from_output, to_node_key } }
//   { kind: 'replace', nodeKey: string }
const pendingTarget = ref(null);

const pickMode = computed(() => pendingTarget.value !== null);

// The root "Add a trigger" adder targets `fromNodeKey: null` — the only spot
// a trigger may ever be picked. "Replace trigger" also only ever offers
// triggers (it's swapping one trigger for another). Every other append/insert
// target is a normal step (logic/action only, see § B1).
const pickKind = computed(() => {
    if (pendingTarget.value?.kind === 'replace') return 'replace-trigger';
    if (pendingTarget.value?.kind === 'append' && pendingTarget.value.fromNodeKey === null) return 'trigger';
    return 'step';
});

function sameTarget(a, b) {
    if (!a || !b || a.kind !== b.kind) return false;
    if (a.kind === 'append') {
        return (a.fromNodeKey ?? null) === (b.fromNodeKey ?? null) && (a.output ?? 'default') === (b.output ?? 'default');
    }
    if (a.kind === 'replace') {
        return a.nodeKey === b.nodeKey;
    }
    return (
        a.edge.from_node_key === b.edge.from_node_key &&
        (a.edge.from_output || 'default') === (b.edge.from_output || 'default') &&
        a.edge.to_node_key === b.edge.to_node_key
    );
}

// Canvas "+" click → arm pick mode for that target, or disarm it if the same
// "+" was already armed (a second click toggles it off).
function onTogglePick(target) {
    if (sameTarget(pendingTarget.value, target)) {
        pendingTarget.value = null;
        return;
    }
    pendingTarget.value = target;
    // Pick mode must always be able to reach the sidebar (brief § C3).
    showLibrary.value = true;
}

function cancelPick() {
    pendingTarget.value = null;
}

// Trigger node's "Replace trigger" action → arm pick mode targeting that
// node for a swap (see replaceTrigger()), same toggle-off-on-repeat-click
// behaviour as the canvas "+" adders.
function onReplaceTrigger(nodeKey) {
    const target = { kind: 'replace', nodeKey };
    if (sameTarget(pendingTarget.value, target)) {
        pendingTarget.value = null;
        return;
    }
    pendingTarget.value = target;
    showLibrary.value = true;
}

// Save-blocked-by-empty-name UX (see `save()`): the backend's
// Store/UpdateAutomationRequest both require `name`, but a fresh flow
// starts with an empty name and only the placeholder "Untitled
// automation" showing — sending that as-is 422s with a generic message
// the UI didn't surface anywhere. `nameInputRef` lets `save()` focus the
// field; `nameInvalid` drives its error ring until the user types again.
const nameInputRef = ref(null);
const nameInvalid = ref(false);

// Undo/redo over the graph (nodes + edges). Every mutation in
// useGraphMutations.js calls `history.record()` after applying its change;
// text edits pass a tag so a burst of typing costs one undo step.
//
// A successful *explicit* save re-baselines the stack (`history.reset()` in
// save()): after a save the graph on screen is the graph on disk, and an undo
// that walks behind that point silently reintroduces edits the user has
// already committed past — with the Save button then offering to write them
// back. A background autosave deliberately does NOT reset: it fires two
// seconds into a pause while the user is still working, and wiping the undo
// stack under a running edit is worse than the thing being fixed.
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

// Live client-side validation: recomputes required-field issues from each
// node's config schema on every edit (A3), so invalid nodes/fields mark up
// immediately without waiting for the server "Validate" round-trip.
const liveIssues = computed(() => computeNodeIssues(automation.value.nodes, props.library));

// node_key → severity for the canvas node cards. Required-field validity is
// always taken from the *live* check (never goes stale after a fix); the server
// contributes structural problems it alone can compute (cycles, edge/trigger
// issues, unknown node types — issues without a `field`).
const validationByNode = computed(() => {
    const map = {};
    for (const issue of liveIssues.value) {
        if (issue.node_key) map[issue.node_key] = 'error';
    }
    for (const issue of issues.value) {
        if (issue.node_key && !issue.field && map[issue.node_key] !== 'error') {
            map[issue.node_key] = issue.level;
        }
    }
    return map;
});

// Per-field errors for the currently selected node, shown inline in the
// ConfigPanel (red field + message). Live-computed so a field clears the
// instant it's filled in.
const selectedNodeFieldErrors = computed(() => {
    const map = {};
    const node = selectedNode.value;
    if (!node) return map;
    for (const handle of missingRequiredHandles(node, props.library)) {
        map[handle] = __('This field is required.');
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

// What the server rejected, kept on screen rather than only thrown at a toast.
//
// `name` is bound to the header input below; everything else — `description`,
// `handle`, and the `nodes.*` / `edges.*` keys the canvas generates — belongs
// to no visible control, so it goes into the collected output above the editor.
// Before this, a 422 produced one toast line and the rest of what the server
// said was discarded.
const serverErrors = ref({});

const nameError = computed(() => serverErrors.value.name ?? null);

// Typing clears the verdict on the name: the ring and the message both refer
// to the value that was rejected, not the one being typed.
function clearNameError() {
    nameInvalid.value = false;
    const { name, ...rest } = serverErrors.value;
    serverErrors.value = rest;
}

const generalErrors = computed(() =>
    Object.entries(serverErrors.value)
        .filter(([key]) => key !== 'name')
        .map(([, message]) => message)
);

async function save({ silent = false } = {}) {
    // The name is required by both Store/UpdateAutomationRequest; catching an
    // empty one here (instead of letting it 422) means the failure is
    // attributable to a specific field the user can see and fix immediately.
    if (!automation.value.name?.trim()) {
        nameInvalid.value = true;
        if (!silent) {
            notify('error', __('Bitte benenne die Automation, bevor du speicherst.'));
            nameInputRef.value?.focus();
            nameInputRef.value?.select();
        }
        return;
    }
    nameInvalid.value = false;

    saving.value = true;
    serverErrors.value = {};
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
            // What is on screen is now what is stored, so the mail list may be
            // edited again — and has to be re-read, because a canvas save can
            // have added, removed or reordered mails.
            savedGraph.value = graphSignature(automation.value);
            refreshMailList();
            if (!silent) {
                history.reset();
                notify('success', __('Automation saved.'));
            }
        } else {
            const { data } = await axios.post(`${props.apiBase}/automations`, payload);
            const created = data?.data ?? data;
            automation.value = { ...automation.value, id: created.id, handle: created.handle };
            savedGraph.value = graphSignature(automation.value);
            // Move from /create to /edit URL so refreshes keep the same automation.
            router.visit(props.indexUrl + '/automations/' + created.id + '/edit', {
                replace: true,
                preserveState: true,
            });
            if (!silent) {
                history.reset();
                notify('success', __('Automation created.'));
            }
        }
    } catch (e) {
        // Kept even for a silent (autosave) rejection: an autosave that keeps
        // failing used to leave no trace anywhere on the page.
        serverErrors.value = errorBag(e);
        nameInvalid.value = Boolean(serverErrors.value.name);
        if (!silent) {
            notify('error', firstMessage(e, __('Save failed.')));
            // Only the autosave path needs the rethrow (useAutosave catches it
            // to keep `lastError`). Rethrowing out of the header button's click
            // handler produced an uncaught promise rejection on every failed
            // save, carrying nothing the user had not already been told.
            return;
        }
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
    } catch (e) {
        // Was a bare `catch {}`: whatever the server said about why validation
        // could not run was thrown away before anyone saw it.
        serverErrors.value = errorBag(e);
        notify('error', firstMessage(e, __('Validation failed.')));
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
            notify('error', data.error_message || __('Test run failed.'));
        } else {
            notify('info', __('Run finished with status: :status', { status: data.status }));
        }
    } catch (e) {
        notify('error', firstMessage(e, __('Test run failed.')));
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
            notify('error', data.message || __('Could not enable.'));
        } else {
            automation.value.enabled = next;
            notify(next ? 'success' : 'info', next ? __('Enabled.') : __('Disabled.'));
        }
    } catch (e) {
        // A refused enable comes back as HTTP 422 carrying `issues[]` — the
        // per-node reasons. axios rejects a 422, so the `ok === false` branch
        // above could never run and those reasons were dropped every time.
        const refused = e?.response?.data?.issues;
        if (Array.isArray(refused) && refused.length) issues.value = refused;
        notify('error', firstMessage(e, __('Toggle failed.')));
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
    } catch (e) {
        notify('error', firstMessage(e, __('Export failed.')));
    }
}

// ---------- The mail list ----------
//
// The projection arrives as a page prop and is kept locally from then on: every
// write endpoint answers with the list as it now is, so the screen is refreshed
// from the response rather than from a second round trip.
//
// These four routes are a JSON API, not Inertia endpoints — they answer with the
// list, not with a page. `router.post()` would demand an Inertia response and
// get a 409 back, so they go through axios like every other mutation on this
// page. It is the one place the page departs from "mutate through Inertia".
const mailList = ref(props.mailList ? { ...props.mailList } : null);
const mailListBusy = ref(false);
const mailListStale = ref(false);

watch(() => props.mailList, (list) => {
    mailList.value = list ? { ...list } : null;
    mailListStale.value = false;
});

const showMailList = computed(() => props.mode === 'edit' && mailList.value !== null);

/**
 * Re-read the stored graph after the list has rewritten it.
 *
 * ChainEditor rebuilds every edge and re-lays the chain out, so the canvas on
 * screen is stale the moment a reorder succeeds. Without this the next Save
 * would write the pre-reorder graph straight back over it.
 */
async function refreshGraph() {
    if (!automation.value.id) return;

    const { data } = await axios.get(`${props.apiBase}/automations/${automation.value.id}`);
    const fresh = data?.data ?? data;

    if (!Array.isArray(fresh?.nodes)) return;

    automation.value = { ...automation.value, nodes: fresh.nodes, edges: fresh.edges ?? [] };
    savedGraph.value = graphSignature(automation.value);
    // The graph on screen is now the graph on disk. An undo that walked behind
    // that point would reintroduce the order the server has just replaced.
    history.reset();
}

/** Re-read the list after the canvas has rewritten the graph. */
async function refreshMailList() {
    if (!props.mailListUrl) return;

    try {
        const { data } = await axios.get(props.mailListUrl);
        mailList.value = data;
        mailListStale.value = false;
    } catch (e) {
        // Not fatal — the save itself succeeded. But a list that silently kept
        // showing the pre-save order would be worse than saying so.
        mailListStale.value = true;
    }
}

async function mailListWrite(request, success) {
    if (!props.mailListUrl) return;

    mailListBusy.value = true;
    try {
        const { data } = await request();
        mailList.value = data;
        mailListStale.value = false;

        // Guarded separately: the write has already succeeded, and reporting
        // "the mail list could not be changed" because the follow-up read
        // failed would tell the user the opposite of what happened.
        try {
            await refreshGraph();
        } catch (e) {
            mailListStale.value = true;
        }

        notify('success', success);
    } catch (e) {
        // A refused write answers 422 carrying both the reason and the list as
        // it still is — so the screen is corrected, not just complained at.
        const refused = e?.response?.data?.list;
        if (refused) mailList.value = refused;
        notify('error', firstMessage(e, __('The mail list could not be changed.')));
    } finally {
        mailListBusy.value = false;
    }
}

function reorderMails(order) {
    mailListWrite(
        () => axios.post(`${props.mailListUrl}/reorder`, { order }),
        __('Mails reordered.'),
    );
}

// The mail the list has asked to delete, held here until it is confirmed. The
// confirmation belongs in the same component as the request that carries it
// out — a delete that asks in one file and fires in another is one refactor
// away from firing without asking.
const pendingMailDelete = ref(null);

function confirmMailDelete() {
    const mail = pendingMailDelete.value;
    pendingMailDelete.value = null;

    if (!mail) return;

    mailListWrite(
        () => axios.delete(`${props.mailListUrl}/${encodeURIComponent(mail.node_key)}`),
        __('Mail removed.'),
    );
}

function insertMail(payload) {
    mailListWrite(
        () => axios.post(props.mailListUrl, payload),
        __('Mail added.'),
    );
}

// ---------- Canvas mutations ----------
//
// Every mutation of the graph itself lives in useGraphMutations.js — the page
// keeps the UI state around them (pick mode, selection, save/validate/test).
// They were inline here until 1.5.5, which is why three defects in them could
// only ever be found by driving a browser.
const {
    insertNode,
    addNode,
    replaceTrigger,
    removeNode,
    updateNodeConfig,
    updateNodeLabel,
    duplicateNode,
    toggleNodeDisabled,
} = useGraphMutations({
    automation,
    selectedNodeKey,
    library: props.library,
    history,
    notify,
});

// Left-library click: if a "+" armed pick mode, the node lands exactly there
// and pick mode exits; a "replace" pick mode swaps the trigger in place
// instead; otherwise fall back to the legacy end-of-flow add.
function onLibraryPick(handle) {
    if (pendingTarget.value) {
        // The pending target was armed against node_keys that may since have
        // been deleted (another edit, an undo, …) — inserting against a
        // stale target would produce an orphaned/corrupt edge. Silently
        // cancel pick mode instead of mutating the graph.
        if (!pendingTargetIsValid(pendingTarget.value, automation.value.nodes)) {
            pendingTarget.value = null;
            notify('info', __('Selection changed — pick cancelled.'));
            return;
        }
        if (pendingTarget.value.kind === 'replace') {
            replaceTrigger(pendingTarget.value.nodeKey, handle);
        } else {
            insertNode(handle, pendingTarget.value);
        }
        pendingTarget.value = null;
        return;
    }
    addNode(handle);
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
    if (e.key === 'Escape' && pendingTarget.value) {
        e.preventDefault();
        cancelPick();
        return;
    }

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

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', updateEditorHeight);
    scheduleHeightUpdate();
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', updateEditorHeight);
});

// The issues Alert renders directly above the editor, so toggling it shifts the
// editor's top offset — remeasure once the DOM reflects the change.
watch(() => issues.value.length, scheduleHeightUpdate);

// Same reason on the way back from the list view: the canvas was display:none
// while it was hidden, so its height has to be measured again once it is not.
watch(view, scheduleHeightUpdate);
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
                    <!-- The ring lives on this wrapper, not the input itself —
                         the input's own `focus:ring-0`/`focus:outline-none`
                         (deliberate, so it reads as inline header text) would
                         otherwise cancel an invalid-state ring the instant
                         `save()` focuses it. -->
                    <span
                        class="rounded-md"
                        :class="nameInvalid && 'ring-2 ring-red-500/70 dark:ring-red-500/70'"
                    >
                        <input
                            ref="nameInputRef"
                            v-model="automation.name"
                            type="text"
                            class="bg-transparent border-none focus:outline-none focus:ring-0 text-[25px] font-medium antialiased min-w-[240px] text-gray-900 dark:text-gray-100"
                            :placeholder="__('Untitled automation')"
                            @input="clearNameError"
                        />
                    </span>
                    <!-- `name` is the one validated key with a control on this
                         page. The ring said something was wrong; it never said
                         what. -->
                    <span
                        v-if="nameError"
                        class="text-sm text-red-600 dark:text-red-400"
                        data-automations-field-error="name"
                    >{{ nameError }}</span>
                    <Badge
                        :color="automation.enabled ? 'green' : 'amber'"
                        :text="automation.enabled ? __('Active') : __('Draft')"
                        pill
                    />
                </div>
            </template>

            <!-- Actions slot: undo/redo, a native "…" actions menu for the
                 secondary controls (Validate / Test / Export / Enable), then the
                 primary Save button — mirroring Statamic's entry-publish header
                 density (title left, primary action + overflow menu right). -->
            <template #actions>
                <!-- Two readings of the same automation. The canvas is what it
                     does; the list is what it sends. Neither replaces the
                     other, which is why this is a view switch and not a
                     separate screen. -->
                <ToggleGroup
                    v-if="showMailList"
                    :model-value="view"
                    size="sm"
                    :aria-label="__('View')"
                    @update:model-value="setView"
                >
                    <ToggleItem value="flow" icon="workflow" :label="__('Flow')" />
                    <ToggleItem value="mails" icon="mail" :label="__('Mails')" />
                </ToggleGroup>

                <div class="flex items-center gap-1">
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

                <Dropdown align="end">
                    <template #trigger>
                        <Button variant="ghost" icon="dots" :aria-label="__('More actions')" />
                    </template>
                    <DropdownItem
                        :text="__('Validate')"
                        icon="clipboard-check"
                        @click="validate"
                    />
                    <DropdownItem
                        :text="__('Test run')"
                        icon="labs-idea-experimental-flask"
                        :disabled="!canTest"
                        @click="testRun"
                    />
                    <DropdownItem
                        :text="__('Export JSON')"
                        icon="download"
                        :disabled="!automation.id"
                        @click="exportJson"
                    />
                    <DropdownSeparator />
                    <DropdownItem
                        :text="automation.enabled ? __('Disable') : __('Enable')"
                        icon="fieldtype-toggle"
                        :disabled="!canEnable || !automation.id"
                        @click="toggleEnabled"
                    />
                </Dropdown>

                <Button
                    :text="saving ? __('Saving…') : __('Save')"
                    variant="primary"
                    :disabled="saving || !canEdit"
                    @click="save()"
                />
            </template>
        </Header>

        <!-- Everything the server rejected that belongs to no control here:
             `description`, `handle`, and the `nodes.*` / `edges.*` keys the
             canvas generates. One toast line used to be the whole of it. -->
        <Alert
            v-if="generalErrors.length"
            variant="error"
            class="mb-4"
            data-automations-form-errors
        >
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in generalErrors" :key="i">{{ message }}</li>
            </ul>
        </Alert>

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

        <!-- The editor fills the CP content area down to the bottom edge: its
             height is measured at runtime from this element's own top offset to
             the viewport bottom (see updateEditorHeight), so it adapts to the CP
             chrome, the addon header, and the optional issues Alert above it
             instead of relying on a hardcoded `100vh - N` guess. -->
        <!-- The list view. Narrower than the canvas and inside the content
             card's padding again: it is reading matter, not a canvas tool. -->
        <div v-if="view === 'mails' && showMailList" class="lg:px-12 pb-8">
            <MailListPanel
                :list="mailList"
                :stats="stats"
                :types="mailTypes"
                :can-edit="canEdit"
                :graph-dirty="graphDirty"
                :busy="mailListBusy"
                :stale="mailListStale"
                @reorder="reorderMails"
                @request-remove="pendingMailDelete = $event"
                @insert="insertMail"
                @open-flow="view = 'flow'"
            />

            <!-- Statamic's confirmation, never window.confirm: browsers
                 suppress native dialogs in plenty of contexts, and where they
                 do not, the dialog steals focus from the CP. -->
            <ConfirmationModal
                v-if="pendingMailDelete"
                :open="true"
                danger
                :title="__('Delete this mail?')"
                :body-text="__('“:label” is removed from the automation, and so is the waiting time in front of it. Anything else in that gap is kept and moves to the next mail.', { label: pendingMailDelete.label || pendingMailDelete.node_key })"
                :button-text="__('Delete mail')"
                :busy="mailListBusy"
                @confirm="confirmMailDelete"
                @cancel="pendingMailDelete = null"
                @update:open="(open) => { if (!open) pendingMailDelete = null; }"
            />
        </div>

        <!-- `v-show`, not `v-if`: unmounting the canvas would throw away the
             user's pan and zoom every time they glanced at the list. -->
        <div
            v-show="view === 'flow' || !showMailList"
            ref="editorEl"
            class="grid rounded-xl border border-gray-200 dark:border-gray-800 bg-content-bg shadow-sm overflow-hidden min-h-[480px] transition-[grid-template-columns] duration-200 ease-in-out"
            :style="{
                gridTemplateColumns: `${showLibrary ? '300px' : '40px'} 1fr ${selectedNode ? '360px' : '0px'}`,
                height: editorHeight,
            }"
        >
            <!-- Left library track: collapses to a 40px rail (never fully to 0)
                 so there's always a click target to reopen it (§ C3). Pick mode
                 forces this back open (see onTogglePick) and disables the
                 in-header collapse button while armed. -->
            <div class="overflow-hidden border-r border-gray-200 dark:border-gray-800">
                <NodeLibrary
                    v-if="showLibrary"
                    :library="library"
                    :pick-mode="pickMode"
                    :pick-kind="pickKind"
                    @add="onLibraryPick"
                    @toggle="showLibrary = false"
                    @cancel-pick="cancelPick"
                />
                <button
                    v-else
                    type="button"
                    class="sa-library-rail"
                    :aria-label="__('Show node library')"
                    @click="showLibrary = true"
                >
                    <Icon name="chevron-right" class="size-4" />
                </button>
            </div>

            <div class="sa-canvas-frame">
                <Canvas
                    :nodes="automation.nodes"
                    :edges="automation.edges"
                    :selected-key="selectedNodeKey"
                    :validation="validationByNode"
                    :library="library"
                    :pending-target="pendingTarget"
                    @select="selectedNodeKey = $event"
                    @toggle-pick="onTogglePick"
                    @remove-node="removeNode"
                    @rename-node="renameNode"
                    @duplicate-node="duplicateNode"
                    @toggle-node-disabled="toggleNodeDisabled"
                    @replace-trigger="onReplaceTrigger"
                />
            </div>

            <!-- Right detail track: collapses to 0 width (via the grid template
                 above) when nothing is selected, so the canvas reclaims the
                 space. The panel itself only mounts while a node is selected —
                 no empty "select a node" state sits in a 0-width column. -->
            <div class="overflow-hidden border-l border-gray-200 dark:border-gray-800 bg-content-bg">
                <ConfigPanel
                    v-if="selectedNode"
                    :node="selectedNode"
                    :library="library"
                    :trigger-output-schema="triggerOutputSchema"
                    :api-base="apiBase"
                    :automation="automation"
                    :last-run="lastRun"
                    :field-errors="selectedNodeFieldErrors"
                    @update:config="updateNodeConfig"
                    @update:label="updateNodeLabel"
                    @duplicate="duplicateNode(selectedNodeKey)"
                    @delete="removeNode(selectedNodeKey)"
                    @deselect="selectedNodeKey = null"
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
