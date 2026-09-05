<template>
    <!-- What this automation has actually been doing.
     *
     * The third reading of one automation, next to the canvas (what it does) and
     * the mail list (what it sends). Everything here is counted out of rows the
     * engine already writes — there is no activity table, the same way there is
     * no enrollment table (see Support\RunStats).
     *
     * One window governs all three tabs. A funnel for the last 7 days next to a
     * protocol for all time would be two screens sharing a heading, and the
     * question people bring here — "is this flow working now" — has one
     * timeframe in it.
     *
     * Presentational apart from its own fetches: it owns the window and the
     * three readings under it, and hands the node numbers back up so the canvas
     * can paint the same figures on its cards. -->
    <div class="space-y-4" data-activity>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :text="__('Activity')" icon="chart-monitoring-indicator" />
                <Description>
                    {{ __('Where people are in this automation, what each step did, and who is still inside it.') }}
                </Description>
            </div>

            <Select
                :model-value="range"
                :options="activity.ranges"
                class="min-w-[180px]"
                :aria-label="__('Timeframe')"
                data-activity-range
                @update:model-value="setRange"
            />
        </div>

        <Alert v-if="failed" variant="warning" data-activity-error>
            {{ __('These numbers could not be refreshed and may be out of date.') }}
        </Alert>

        <!-- The funnel, as five figures. Card + Subheading + Heading, the three
             parts every dashboard in this addon family is built from. -->
        <!-- The track count is an inline style, not `md:grid-cols-5`, and that
             is a workaround for the Control Panel's cascade rather than a
             preference. Every installed addon ships its own Tailwind build, all
             of them are loaded into the same document, and each one contains an
             unprefixed `.grid-cols-2` at the same specificity as this addon's
             `.md\:grid-cols-5`. Whichever sheet the CP happens to load last
             wins, so the responsive variant lost to a base utility from a
             sibling — five tiles rendered two across on a 1600px screen. An
             inline style is above all of them, and `auto-fit` makes the
             breakpoint unnecessary anyway. -->
        <div
            class="grid gap-3"
            style="grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr))"
            data-activity-funnel
        >
            <Card v-for="tile in tiles" :key="tile.key" class="h-full">
                <Subheading :text="tile.label" />
                <Heading
                    size="2xl"
                    class="mt-2 tabular-nums"
                    :class="tile.tone"
                    :text="tile.value"
                    :data-activity-tile="tile.key"
                />
            </Card>
        </div>

        <Tabs v-model="tab">
            <TabList class="mb-3">
                <TabTrigger name="steps" data-activity-tab="steps">{{ __('Steps') }}</TabTrigger>
                <TabTrigger name="log" data-activity-tab="log">{{ __('Log') }}</TabTrigger>
                <TabTrigger name="people" data-activity-tab="people">
                    <span class="flex items-center gap-1.5">
                        {{ __('In the workflow') }}
                        <Badge v-if="funnel.in_progress" :text="String(funnel.in_progress)" size="sm" pill />
                    </span>
                </TabTrigger>
            </TabList>

            <!-- ── Steps ─────────────────────────────────────────────────────
                 Where people stop. The five numbers above say how many left;
                 only this says where, which is the half that names what to fix.

                 The share is measured against the busiest step rather than
                 against the first one: a branch splits the flow, and a
                 percentage of "the trigger" would read as a 60% drop where
                 nothing was lost at all. With nothing to measure there is no
                 percentage at all — the empty state says so in words.

                 The same `Listing` the two tabs beside it use, in its
                 client-side mode (`:items`): the figures are counted out of the
                 payload this panel already holds, so a server route and a
                 second round trip would buy nothing. What it does buy is what
                 the hand-built card list could not have: column headings, a
                 sort that works on every column, and a per-row menu — and five
                 steps that read as five rows instead of filling the screen. -->
            <TabContent name="steps">
                <Card v-if="!steps.length">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('This automation has no steps yet.') }}
                    </p>
                </Card>

                <Card v-else-if="!entered">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Nobody has been through this automation in this timeframe.') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Widen the timeframe, or run a test to see the steps light up.') }}
                    </p>
                </Card>

                <!-- No `action-url`, and therefore deliberately no checkbox
                     column: the steps are counted rows, not records, and there
                     is nothing an addon could do to a selection of them. A
                     checkbox that selects and then offers nothing is worse than
                     no checkbox. -->
                <Listing
                    v-else
                    :items="steps"
                    :columns="stepColumns"
                    :allow-bulk-actions="false"
                    :allow-presets="false"
                    :allow-search="false"
                    :allow-customizing-columns="false"
                    :show-pagination-totals="false"
                    :show-pagination-page-links="false"
                    :show-pagination-per-page-selector="false"
                    sort-column="position"
                    sort-direction="asc"
                >
                    <template #cell-position="{ row }">
                        <span class="text-2xs tabular-nums text-gray-500" :data-activity-step="row.node_key">
                            {{ row.position }}
                        </span>
                    </template>
                    <template #cell-label="{ row }">
                        <span class="font-medium">{{ row.label }}</span>
                        <!-- No `size="sm"`: that variant is
                             `rounded-[0.1875rem]`, a 3px radius that reads as a
                             broken button next to the default `rounded-sm`
                             every other badge in this addon uses
                             (ui-vocabulary §22). -->
                        <Badge v-if="row.removed" color="amber" :text="__('No longer in the flow')" class="ms-1" />
                    </template>
                    <template #cell-reached="{ row }">
                        <span class="text-2xs tabular-nums">{{ row.reached }}</span>
                    </template>
                    <template #cell-share="{ row }">
                        <span class="text-2xs tabular-nums text-gray-600 dark:text-gray-400">{{ row.share }}%</span>
                    </template>
                    <template #cell-completed="{ row }">
                        <span class="text-2xs tabular-nums">{{ row.completed }}</span>
                    </template>
                    <template #cell-failed="{ row }">
                        <span
                            class="text-2xs tabular-nums"
                            :class="row.failed ? 'text-red-600 dark:text-red-400' : 'text-gray-500'"
                        >{{ row.failed }}</span>
                    </template>
                    <template #cell-stuck="{ row }">
                        <span class="text-2xs tabular-nums" :data-activity-step-stuck="row.node_key">{{ row.stuck }}</span>
                    </template>

                    <!-- Both items lead somewhere that already exists: the
                         protocol next door, filtered to this step, and the CSV
                         the toolbar above builds. A row menu whose entries only
                         restate the row would be decoration. -->
                    <template #prepended-row-actions="{ row }">
                        <DropdownItem
                            icon="list-ul"
                            :text="__('Show in the log')"
                            @click="showStepInLog(row)"
                        />
                        <DropdownItem
                            icon="download"
                            :text="__('Export this step')"
                            @click="exportCsv(row.node_key)"
                        />
                    </template>
                </Listing>
            </TabContent>

            <!-- ── Log ───────────────────────────────────────────────────────
                 Every step of every run, newest first, really paginated. The
                 filters are keyed into the listing rather than pushed at it: a
                 changed filter is a different query and page one of it, and
                 remounting says that without a second code path for "reset the
                 page while keeping the sort". -->
            <TabContent name="log">
                <!-- The two filters are boxed to a fixed width and the widths
                     are inline, for the reason the tile grid above is: a
                     sibling addon's Tailwind sheet can win a same-specificity
                     utility, and a Select that falls back to full width takes
                     the whole row with it. `placeholder` rather than a label on
                     the empty option — the CP's Select shows the placeholder
                     for an empty value either way, so the option label would
                     never be the thing on screen. -->
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <div style="width: 15rem">
                        <Select
                            v-model="node"
                            :options="nodeOptions"
                            :placeholder="__('Any step')"
                            :aria-label="__('Step')"
                            data-activity-node-filter
                        />
                    </div>
                    <div style="width: 13rem">
                        <Select
                            v-model="status"
                            :options="activity.statusOptions"
                            :placeholder="__('Any outcome')"
                            :aria-label="__('Outcome')"
                            data-activity-status-filter
                        />
                    </div>
                    <Button
                        class="ms-auto"
                        icon="download"
                        :text="__('Export CSV')"
                        data-activity-export
                        @click="exportCsv"
                    />
                </div>

                <Listing
                    :key="logKey"
                    :url="activity.logUrl"
                    :columns="activity.logColumns"
                    :additional-parameters="logParameters"
                    :allow-bulk-actions="false"
                    :allow-presets="false"
                    :allow-search="false"
                    preferences-prefix="statamic-automations.activity-log"
                    sort-column="created_at"
                    v-model:sort-direction="sortDirection"
                >
                    <template #cell-created_at="{ row }">
                        <Link :href="row.show_url" class="text-2xs tabular-nums">
                            {{ formatDate(row.created_at) }}
                        </Link>
                    </template>
                    <template #cell-node_label="{ row }">
                        <span class="font-medium">{{ row.node_label }}</span>
                        <Badge v-if="row.node_removed" color="amber" :text="__('Removed')" class="ms-1" />
                    </template>
                    <template #cell-node_type="{ row }">
                        <code class="text-xs text-gray-500">{{ row.node_type }}</code>
                    </template>
                    <template #cell-status="{ row }">
                        <Badge :color="statusColor(row.status)" :text="statusLabel(row.status)" />
                    </template>
                    <template #cell-subject="{ row }">
                        <span class="text-2xs">{{ row.subject || '—' }}</span>
                    </template>
                    <template #cell-duration_ms="{ row }">
                        <span class="text-2xs">{{ row.duration_ms == null ? '—' : row.duration_ms + ' ms' }}</span>
                    </template>
                    <template #cell-error_message="{ row }">
                        <span class="text-2xs text-red-600 dark:text-red-400">{{ row.error_message }}</span>
                    </template>
                </Listing>
            </TabContent>

            <!-- ── In the workflow ───────────────────────────────────────────
                 A run whose status is queued, running or waiting IS an
                 enrollment; there is no second table and there is not meant to
                 be one. So this is those runs, with the person they are about
                 and the step they are parked on. -->
            <TabContent name="people">
                <!-- "more" only reads as "more" when something stands below
                     it. With an empty table underneath, the sentence pointed
                     at nothing. -->
                <!-- One wording for both cases. ":n more" read as a lie
                     whenever the table below it was empty, and the panel has
                     no honest way to know the row count of a listing that
                     fetches its own pages. -->
                <p v-if="withoutSubject" class="mb-3 text-sm text-gray-500" data-activity-anonymous>
                    {{ __('Also in the flow: :n runs with no person attached — a scheduled sweep, or a webhook that named nobody.', { n: withoutSubject }) }}
                </p>

                <!-- Deliberately without `range`: this tab asks who is inside
                     the flow *now*, not during a period. Narrowed by the
                     window it dropped exactly the people worth seeing —
                     somebody enrolled forty days ago and parked in a sixty-day
                     wait vanished at the default "last 30 days". -->
                <Listing
                    :url="activity.subjectsUrl"
                    :columns="activity.subjectColumns"
                    :allow-bulk-actions="false"
                    :allow-presets="false"
                    :allow-search="false"
                    preferences-prefix="statamic-automations.activity-people"
                >
                    <template #cell-subject="{ row }">
                        <Link v-if="row.contact_url" :href="row.contact_url" class="font-medium">{{ row.subject }}</Link>
                        <span v-else class="font-medium">{{ row.subject }}</span>
                    </template>
                    <template #cell-node_label="{ row }">
                        <span v-if="row.node_label">{{ row.node_label }}</span>
                        <span v-else class="text-gray-500">{{ __('Not started yet') }}</span>
                        <Badge v-if="row.node_removed" color="amber" :text="__('Removed')" class="ms-1" />
                    </template>
                    <template #cell-status="{ row }">
                        <Badge :color="statusColor(row.status)" :text="statusLabel(row.status)" />
                    </template>
                    <template #cell-enrolled_at="{ row }">
                        <Link :href="row.show_url" class="text-2xs tabular-nums">{{ formatDate(row.enrolled_at) }}</Link>
                    </template>
                </Listing>
            </TabContent>
        </Tabs>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    Description,
    DropdownItem,
    Heading,
    Listing,
    Select,
    Subheading,
    TabContent,
    TabList,
    Tabs,
    TabTrigger,
} from '@statamic/cms/ui';
import axios from 'axios';

import { statusColor } from '../../support/runStatus.js';
import { computeLayout } from '../../composables/useAutoLayout.js';

const props = defineProps({
    /** The `activity` page prop — see AutomationsPageController::activityPayload. */
    activity: { type: Object, required: true },
    /** The graph as it stands on the canvas, for the order and the names. */
    nodes: { type: Array, default: () => [] },
    edges: { type: Array, default: () => [] },
    /** handle → label, so a step reads as "Send email" rather than `send_email`. */
    typeLabels: { type: Object, default: () => ({}) },
});

// The canvas paints the same figures on its cards, so a changed window has to
// reach it. Emitted rather than written: the page owns what the canvas is told.
const emit = defineEmits(['update:nodes-stats']);

const tab = ref('steps');
const range = ref(props.activity.range);
const funnel = ref({ ...props.activity.funnel });
const nodeStats = ref({ ...props.activity.nodes });
// Held locally rather than read off the prop, because it changes with the
// window and a prop is the server's word, not this component's.
const withoutSubject = ref(props.activity.without_subject ?? 0);
const failed = ref(false);

const node = ref('');
const status = ref('');

// Mirrors the table's sort so the export can follow it. `Listing` restores
// the reader's remembered choice into this on mount.
const sortDirection = ref('desc');

/**
 * The word for a status, as the filter beside it spells it.
 *
 * The cells printed the raw `success` / `failed` while the dropdown two
 * centimetres away offered "Erfolg" and "Fehlgeschlagen" — the same fact in
 * two languages, on one screen. The server already sends those labels; this
 * reads them from there rather than keeping a second list in the browser.
 */
function statusLabel(value) {
    const option = (props.activity.statusOptions ?? []).find((entry) => entry.value === value);

    return option ? option.label : value;
}

/**
 * A changed filter is a different query, and page one of it. Keying the listing
 * on the filters is what says that: the component remounts and asks the server
 * afresh, instead of the screen needing a second path to reset the page number
 * while a stale page is still on it.
 */
const logKey = computed(() => `${range.value}|${node.value}|${status.value}`);

const logParameters = computed(() => ({
    range: range.value,
    node: node.value || undefined,
    status: status.value || undefined,
}));

watch(() => props.activity, (next) => {
    range.value = next.range;
    funnel.value = { ...next.funnel };
    nodeStats.value = { ...next.nodes };
    withoutSubject.value = next.without_subject ?? 0;
});

async function setRange(next) {
    if (! next || next === range.value) return;

    range.value = next;
    failed.value = false;

    try {
        const { data } = await axios.get(props.activity.overviewUrl, { params: { range: next } });

        funnel.value = data.funnel ?? {};
        // Assigned, not merged: the watcher below is what tells the canvas, and
        // a node that has dropped out of the new window must lose its numbers
        // rather than keep the old ones.
        nodeStats.value = data.nodes ?? {};
        withoutSubject.value = data.without_subject ?? 0;
    } catch (e) {
        // The window on screen is the one that was asked for; what is under it
        // may be the previous answer. Saying so beats a silent disagreement
        // between the Select and the numbers.
        failed.value = true;
    }
}

watch(nodeStats, (stats) => emit('update:nodes-stats', stats), { immediate: true });

// ---------- The five figures ----------

const tiles = computed(() => {
    const f = funnel.value ?? {};

    return [
        { key: 'enrolled', label: __('Enrolled'), value: String(f.enrolled ?? 0), tone: '' },
        { key: 'in_progress', label: __('In progress'), value: String(f.in_progress ?? 0), tone: '' },
        // Not `__('Completed')`. JSON dictionaries merge across the whole CP,
        // and LeadHub owns that string for a finished *task* ("Erledigt"), so
        // translating it here would have overwritten their word everywhere.
        // The house rule for a collision is to make the source string specific
        // rather than to drop the key — otherwise this tile reads English
        // between four German ones on an install without LeadHub.
        { key: 'completed', label: __('Ran to the end'), value: String(f.completed ?? 0), tone: '' },
        { key: 'exited', label: __('Exited'), value: String(f.exited ?? 0), tone: '' },
        {
            key: 'failed',
            label: __('Failed'),
            value: String(f.failed ?? 0),
            tone: f.failed ? 'text-red-600 dark:text-red-400' : '',
        },
    ];
});

// ---------- The steps ----------

/**
 * The steps in the order the canvas draws them, plus any step that has runs but
 * is no longer on the canvas.
 *
 * Reusing the canvas layout is what keeps the two in the same order without a
 * second traversal to disagree with the first. A node run whose node has since
 * been deleted is appended rather than dropped: it is a thing that happened, and
 * a funnel that quietly loses a step it no longer recognises is the version of
 * this screen that lies.
 */
const orderedKeys = computed(() => {
    const graph = props.nodes ?? [];
    const positions = graph.length ? computeLayout(graph, props.edges ?? []).positions : {};

    const inGraph = [...graph]
        .sort((a, b) => {
            const pa = positions[a.node_key] ?? { x: 0, y: 0 };
            const pb = positions[b.node_key] ?? { x: 0, y: 0 };
            return pa.y - pb.y || pa.x - pb.x;
        })
        .map((n) => n.node_key);

    const known = new Set(inGraph);
    const orphans = Object.keys(nodeStats.value ?? {}).filter((key) => ! known.has(key));

    return { inGraph, orphans };
});

function labelFor(key) {
    const found = (props.nodes ?? []).find((n) => n.node_key === key);
    if (! found) return key;
    return found.label || props.typeLabels[found.type] || found.type || key;
}

/** The busiest step. Zero means nothing ran, and then there are no bars at all. */
const entered = computed(() =>
    Object.values(nodeStats.value ?? {}).reduce((max, s) => Math.max(max, s.reached ?? 0), 0),
);

const steps = computed(() => {
    const { inGraph, orphans } = orderedKeys.value;
    const base = entered.value;

    const build = (key, removed, position) => {
        const stats = nodeStats.value?.[key] ?? { reached: 0, completed: 0, failed: 0 };
        const reached = stats.reached ?? 0;
        const completed = stats.completed ?? 0;
        const broke = stats.failed ?? 0;
        const stuck = Math.max(0, reached - completed - broke);

        return {
            // `Listing` keys its rows and its selections by `id`. The node key
            // is the only identifier a step has, and it is unique per
            // automation by construction.
            id: key,
            node_key: key,
            // Carried as a field rather than derived from the array index,
            // because the table may be sorted by any column and the flow
            // position has to survive that — it is the one number that says
            // where in the automation a step sits.
            position,
            label: removed ? key : labelFor(key),
            removed,
            reached,
            // Guarded, and the guard is the point: an automation nobody has been
            // through divides by zero, and `NaN%` on every row is how that shows
            // up on screen.
            share: base > 0 ? Math.round((reached / base) * 100) : 0,
            completed,
            failed: broke,
            // The third figure of the row, and the one nothing else carries:
            // reached, minus those that got through, minus those that broke.
            stuck,
        };
    };

    return [
        ...inGraph.map((key, index) => build(key, false, index + 1)),
        ...orphans.map((key, index) => build(key, true, inGraph.length + index + 1)),
    ];
});

/**
 * The steps table, as columns.
 *
 * Computed rather than a module constant so the labels are translated at render
 * time — a `const` evaluated at import time asks `__()` before the Control Panel
 * has installed it.
 *
 * `Step` and `Position` are not this addon's words: statamic/cms already
 * translates the first and the addon's own dictionary carries the second, so
 * neither is redefined here (see tests/Unit/TranslationKeyOwnershipTest.php).
 */
const stepColumns = computed(() => [
    { field: 'position', label: __('Position'), sortable: true, visible: true },
    { field: 'label', label: __('Step'), sortable: true, visible: true },
    { field: 'reached', label: __('Reached'), sortable: true, visible: true },
    { field: 'share', label: __('Share'), sortable: true, visible: true },
    { field: 'completed', label: __('Got through'), sortable: true, visible: true },
    { field: 'failed', label: __('Failed here'), sortable: true, visible: true },
    { field: 'stuck', label: __('Did not continue'), sortable: true, visible: true },
]);

/**
 * Take one step's rows to the protocol next door.
 *
 * The filter and the tab are the two halves of the same act: switching without
 * the filter lands on every step's runs, and filtering without switching hides
 * the result behind a tab nobody pressed.
 */
function showStepInLog(row) {
    node.value = row.node_key;
    status.value = '';
    tab.value = 'log';
}

// ---------- The log's step filter ----------

const nodeOptions = computed(() => {
    const { inGraph, orphans } = orderedKeys.value;

    return [
        { value: '', label: __('Any step') },
        ...inGraph.map((key) => ({ value: key, label: labelFor(key) })),
        ...orphans.map((key) => ({ value: key, label: __(':key (removed)', { key }) })),
    ];
});

// ---------- Odds and ends ----------

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

/**
 * The export is a file the server builds, so it is a plain navigation — the
 * blob dance the JSON export elsewhere on this page performs exists because
 * that payload is assembled in the browser, and copying it here would only add
 * a round trip and a memory copy of the whole log.
 */
function exportCsv(nodeKey = null) {
    // The sort direction too, and not as a nicety: `Listing` remembers the
    // reader's choice under `preferences-prefix`, so somebody who once clicked
    // "When" ascending got every export from then on in the opposite order to
    // the table it came from — while the file claims to be that table.
    const params = new URLSearchParams({ range: range.value, order: sortDirection.value });

    // The toolbar button is wired as `@click="exportCsv"`, so the first
    // argument there is a MouseEvent, not a step. Anything that is not a
    // string means "the whole protocol, as it is filtered".
    const step = typeof nodeKey === 'string' && nodeKey ? nodeKey : node.value;

    if (step) params.set('node', step);
    if (status.value) params.set('status', status.value);

    window.open(`${props.activity.exportUrl}?${params.toString()}`, '_blank');
}
</script>
