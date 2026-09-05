<template>
    <!-- The mails an automation sends, as a list.
     *
     * **This is a list of the e-mails, not a picture of the automation.** That
     * sentence decides the whole component. It is why the list renders for a
     * branched flow as happily as for a straight one — a fork still has a
     * knowable set of mails — and why a mail only some readers get is labelled
     * `Conditional` with the fork named next to it, instead of being hidden or
     * redrawn as a diagram. The diagram already exists; it is the canvas.
     *
     * Reading is always on. Editing is bound to Sequence\LinearityRule, and when
     * it is off, LinearityNotice says which of its seven conditions is broken.
     *
     * Presentational apart from one seam: every mutation the rows themselves
     * offer leaves as an event, so the page owns the HTTP and this component can
     * be mounted in a test with nothing but props. The exception is the delete,
     * which is a Statamic action and therefore talks to `actionUrl` from inside
     * `Listing` — that is the price of a checkbox column whose selection can
     * actually do something, and the page is told to re-read afterwards through
     * `refresh`.
     *
     * The rows are a `Listing` in its client-side mode (`:items`), the same
     * component the runs and protocol screens use. It was a stack of cards until
     * 2.15: no column headings, no sort, no per-row menu, and five mails filling
     * a screen. Nothing about "a list of the mails" needed that to be bespoke. -->
    <div class="space-y-4" data-mail-list>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :text="__('Mails')" icon="mail" />
                <Description>
                    {{ __('What this automation sends, in order. Click a name to read and edit that mail. Every gap is measured from the mail before it, never from the start.') }}
                </Description>
            </div>

            <Button
                v-if="canMutate"
                variant="primary"
                size="sm"
                icon="plus"
                :text="__('Add a mail')"
                :disabled="types.length === 0"
                data-mail-add
                @click="openAddForm"
            />
        </div>

        <!-- The enrollment funnel: how many are in this flow, how many got
             through, how many left. Read out of the runs that already exist
             (Support\RunStats), so it costs nothing to show here.

             A line above the table rather than columns in it, because these are
             counts for the whole automation: as columns they would repeat the
             same four numbers on every row and invite the reading that they
             belong to that mail. The colours are gone with the same reasoning —
             four hues carried no meaning the labels did not already carry, and
             they were the loudest thing on a screen whose job is a list. Red
             stays on `Failed`, which is the one of the five that is not just a
             count but a verdict, and it appears only when there is one. -->
        <div v-if="stats" class="flex flex-wrap gap-2" data-mail-list-stats>
            <Badge :prepend="__('Enrolled')" :text="String(stats.enrolled ?? 0)" />
            <Badge :prepend="__('In progress')" :text="String(stats.in_progress ?? 0)" />
            <Badge :prepend="__('Completed')" :text="String(stats.completed ?? 0)" />
            <Badge :prepend="__('Exited')" :text="String(stats.exited ?? 0)" />
            <Badge v-if="stats.failed" color="red" :prepend="__('Failed')" :text="String(stats.failed)" />
        </div>

        <LinearityNotice
            v-if="!editable"
            :reasons="list?.reasons ?? []"
            @open-flow="$emit('open-flow')"
        />

        <Alert v-else-if="!canEdit" variant="default" data-mail-list-readonly>
            {{ __('You can read this list. Changing it needs the "edit automations" permission.') }}
        </Alert>

        <Alert v-else-if="graphDirty" variant="warning" data-mail-list-dirty>
            {{ __('The canvas has changes that are not saved yet. Save them first — an edit made from this list is written straight to the stored automation and would be overwritten by the next save.') }}
        </Alert>

        <Alert v-if="stale" variant="warning" data-mail-list-stale>
            {{ __('This list could not be refreshed and may be out of date. Reload the page to see the stored order.') }}
        </Alert>

        <Card v-if="mails.length === 0">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('This automation sends no mail yet.') }}
            </p>
            <p class="mt-1 text-sm text-gray-500">
                {{ canMutate
                    ? __('Add one here, or build the flow on the canvas.')
                    : __('Add a mail step on the canvas and it will appear here.') }}
            </p>
        </Card>

        <!-- `action-url` only while the list may actually be changed. It is
             what turns the checkbox column on, and Statamic's own rule is that
             a selection must have somewhere to go: with a branched flow, a
             reader without the permission, or a canvas holding unsaved edits,
             there is nothing a selection could do, so there are no checkboxes
             either. Reordering stays in the row menu rather than becoming drag
             handles: core hides the checkbox column whenever `reorderable` is
             on, and a permanent trade of multi-select for dragging is the wrong
             way round for a list this short. -->
        <Listing
            v-else
            :items="rows"
            :columns="columns"
            :action-url="canMutate ? actionUrl : undefined"
            :allow-presets="false"
            :allow-search="false"
            :allow-customizing-columns="false"
            :show-pagination-totals="false"
            :show-pagination-page-links="false"
            :show-pagination-per-page-selector="false"
            sort-column="position"
            sort-direction="asc"
            @refreshing="$emit('refresh')"
        >
            <template #cell-position="{ row }">
                <span class="text-2xs tabular-nums text-gray-500" :data-mail-row="row.node_key">{{ row.position }}</span>
            </template>

            <template #cell-label="{ row }">
                <!-- The name opens the mail. A button rather than the whole
                     row: the row carries a checkbox and a menu of its own, and
                     a click target that swallowed them would take both with it.
                     A button also reaches the keyboard, which a clickable cell
                     does not. `data-interactive` keeps core's row-click
                     handler off it. -->
                <button
                    type="button"
                    data-interactive
                    class="cursor-pointer truncate text-start font-medium text-gray-900 hover:underline dark:text-gray-100"
                    :data-mail-open="row.node_key"
                    @click="$emit('open', row.mail)"
                >
                    {{ row.label }}
                </button>
                <Badge v-if="row.disabled" :text="__('Disabled')" class="ms-1" />
            </template>

            <template #cell-reference="{ row }">
                <code v-if="row.reference" class="text-xs text-gray-500">{{ row.reference }}</code>
                <span v-else class="text-gray-500">—</span>
            </template>

            <template #cell-delay="{ row }">
                <span class="text-2xs" :data-mail-delay="row.node_key">{{ row.delay }}</span>
            </template>

            <template #cell-condition="{ row }">
                <template v-if="row.conditional">
                    <Badge
                        color="amber"
                        icon="git"
                        :text="__('Conditional')"
                        :data-mail-conditional="row.node_key"
                    />
                    <span class="ms-1 text-2xs text-gray-600 dark:text-gray-400">{{ row.condition }}</span>
                </template>
                <span v-else class="text-gray-500">—</span>
            </template>

            <template #cell-also_runs="{ row }">
                <span v-if="row.also_runs" class="text-2xs text-gray-500">{{ row.also_runs }}</span>
                <span v-else class="text-gray-500">—</span>
            </template>

            <!-- Reading is always on, so "open" is here for everybody. The two
                 moves are prepended items rather than drag handles for the
                 reason the old arrow buttons were: a drag is unreachable from a
                 keyboard and silent to a screen reader. Delete is not here — it
                 is the Statamic action the `action-url` above serves, so the
                 row menu and the bulk toolbar delete a mail by the same code
                 path, with the same confirmation. -->
            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="mail" :text="__('Open this mail')" @click="$emit('open', row.mail)" />
                <template v-if="canMutate">
                    <DropdownItem
                        v-if="row.position > 1"
                        icon="arrow-up"
                        :text="__('Move up')"
                        @click="move(row.position - 1, -1)"
                    />
                    <DropdownItem
                        v-if="row.position < rows.length"
                        icon="arrow-down"
                        :text="__('Move down')"
                        @click="move(row.position - 1, 1)"
                    />
                </template>
            </template>
        </Listing>

        <p v-if="list?.tail?.length" class="text-sm text-gray-500" data-mail-list-tail>
            {{ __(':n more step(s) run after the last mail.', { n: list.tail.length }) }}
        </p>

        <Modal
            :open="adding"
            :title="__('Add a mail')"
            @update:open="(open) => { if (! open) adding = false; }"
        >
            <div class="space-y-4">
                <Field :label="__('Step')" :instructions="__('Which kind of step sends this mail.')">
                    <Select
                        :model-value="form.type"
                        :options="typeOptions"
                        @update:model-value="form.type = $event"
                    />
                </Field>

                <Field :label="__('Name')" :instructions="__('Optional. Shown on the canvas and in this list until the step has a subject.')">
                    <Input :model-value="form.label" @update:model-value="form.label = $event" />
                </Field>

                <Field :label="__('Wait before it')" :instructions="__('Counted from the mail before it. Zero sends it as soon as the one before is out.')">
                    <div class="flex gap-2">
                        <Input
                            type="number"
                            class="w-28"
                            :input-attrs="{ min: 0 }"
                            :model-value="form.amount"
                            @update:model-value="form.amount = $event"
                        />
                        <Select
                            :model-value="form.unit"
                            :options="unitOptions"
                            @update:model-value="form.unit = $event"
                        />
                    </div>
                </Field>

                <Field :label="__('Position')">
                    <Select
                        :model-value="form.after"
                        :options="afterOptions"
                        @update:model-value="form.after = $event"
                    />
                </Field>
            </div>

            <template #footer>
                <Button :text="__('Cancel')" @click="adding = false" />
                <Button
                    variant="primary"
                    :text="__('Add mail')"
                    :disabled="! form.type || busy"
                    data-mail-add-submit
                    @click="submitAdd"
                />
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import {
    Alert,
    Badge,
    Button,
    Card,
    Description,
    DropdownItem,
    Field,
    Heading,
    Input,
    Listing,
    Modal,
    Select,
} from '@statamic/cms/ui';

import LinearityNotice from './LinearityNotice.vue';
import { durationParts, movedOrder } from '../../support/mailList.js';

const props = defineProps({
    /** The projection: `{ mails, editable, reasons, trigger, tail }`. */
    list: { type: Object, default: null },
    /** `Support\RunStats` for this automation, or null. */
    stats: { type: Object, default: null },
    /** Node types that may be inserted as a mail: `[{ handle, label }]`. */
    types: { type: Array, default: () => [] },
    /** Whether the user holds "edit automations". */
    canEdit: { type: Boolean, default: true },
    /** Whether the canvas holds edits that are not saved yet. */
    graphDirty: { type: Boolean, default: false },
    /** A write is in flight. */
    busy: { type: Boolean, default: false },
    /** The last refresh failed, so what is on screen may be behind the server. */
    stale: { type: Boolean, default: false },
    /**
     * Where `Listing` asks for, and runs, the actions a selection may perform
     * — `MailListController::actionList` / `runAction`. Null switches the
     * checkbox column off, because Statamic ties selections to actions and a
     * checkbox with nothing behind it is worse than none.
     */
    actionUrl: { type: String, default: null },
});

const emit = defineEmits(['reorder', 'insert', 'open-flow', 'open', 'refresh']);

const mails = computed(() => props.list?.mails ?? []);
const editable = computed(() => Boolean(props.list?.editable));

/**
 * Three separate gates, each with its own message above, because they call for
 * three different actions: rework the flow, ask for a permission, press Save.
 */
const canMutate = computed(
    () => editable.value && props.canEdit && ! props.graphDirty && ! props.busy,
);

const UNIT_LABELS = {
    day: (n) => (n === 1 ? __('1 day') : __(':n days', { n })),
    hour: (n) => (n === 1 ? __('1 hour') : __(':n hours', { n })),
    minute: (n) => (n === 1 ? __('1 minute') : __(':n minutes', { n })),
    second: (n) => (n === 1 ? __('1 second') : __(':n seconds', { n })),
};

/**
 * The gap in front of one mail, in words.
 *
 * Always relative to what precedes it — the mail before, or the trigger for the
 * first row. An absolute "day 7" would be a lie the moment somebody moves a row
 * above it, which is exactly what this list invites them to do.
 */
function delayLabel(mail, index) {
    const seconds = Number(mail?.delay?.seconds ?? 0);
    const sources = mail?.delay?.sources ?? [];
    const first = index === 0;

    if (seconds > 0) {
        const duration = durationParts(seconds)
            .map(({ value, unit }) => UNIT_LABELS[unit](value))
            .join(' ');

        return first
            ? __('Sent :duration after the trigger', { duration })
            : __('Sent :duration after the previous mail', { duration });
    }

    // No seconds but a waiting step all the same: a `wait_until` names a moment
    // ("next Tuesday at 9"), which no number of seconds describes. Printing a
    // made-up number would be worse than naming the rule.
    if (sources.length) {
        return first
            ? __('Sent at a scheduled moment after the trigger')
            : __('Sent at a scheduled moment after the previous mail');
    }

    return first
        ? __('Sent as soon as the trigger fires')
        : __('Sent as soon as the previous mail is out');
}

function alsoRuns(mail) {
    return (mail.also_runs ?? []).map((step) => step.node_key).join(', ');
}

// ---------- The table ----------

/**
 * One row per mail, flattened.
 *
 * `Listing` sorts and keys by the row's own scalar fields, so anything a column
 * shows has to be a field rather than something a cell computes: sorting by
 * "Sending" has to sort by the sentence the reader sees. The mail itself rides
 * along under `mail` for the events that hand it back to the page.
 */
const rows = computed(() =>
    mails.value.map((mail, index) => ({
        // `Listing` keys rows and selections by `id`, and the node key is the
        // only identifier a mail has inside its automation.
        id: mail.node_key,
        node_key: mail.node_key,
        // The flow position, carried rather than derived from the array index,
        // because the table may be sorted by any column and "which mail comes
        // first" must survive that — the reorder items read it.
        position: index + 1,
        label: mail.label || mail.node_key,
        reference: mail.reference || '',
        delay: delayLabel(mail, index),
        conditional: Boolean(mail.conditional),
        condition: mail.condition ?? '',
        disabled: Boolean(mail.disabled),
        also_runs: alsoRuns(mail),
        mail,
    })),
);

/**
 * Computed rather than a module constant, so `__()` is asked at render time
 * instead of at import time, before the Control Panel has installed it.
 *
 * `Position` is this addon's own word (the add form's field carries it too);
 * everything else here is a source string no other package owns — see
 * tests/Unit/TranslationKeyOwnershipTest.php for why that matters.
 */
const columns = computed(() => [
    { field: 'position', label: __('Position'), sortable: true, visible: true },
    { field: 'label', label: __('Mail'), sortable: true, visible: true },
    { field: 'reference', label: __('Reference'), sortable: true, visible: true },
    { field: 'delay', label: __('Sending'), sortable: true, visible: true },
    { field: 'condition', label: __('Condition'), sortable: true, visible: true },
    { field: 'also_runs', label: __('Runs in this gap'), sortable: true, visible: true },
]);

// ---------- Reordering ----------

function move(index, delta) {
    const order = movedOrder(mails.value.map((mail) => mail.node_key), index, delta);

    if (! order) return;

    emit('reorder', order);
}

// ---------- Inserting ----------

const adding = ref(false);
const form = ref({ type: '', label: '', amount: 0, unit: 'days', after: '' });

const typeOptions = computed(() =>
    props.types.map((type) => ({ value: type.handle, label: type.label || type.handle })),
);

const unitOptions = computed(() => [
    // Grossgeschrieben, und das ist kein Schoenheitsfehler.
    //
    // JSON-Uebersetzungen aller Pakete landen in EINEM Woerterbuch (siehe
    // tests/Unit/TranslationKeyOwnershipTest.php). Die kleingeschriebenen
    // `days`/`hours`/`minutes` gehoeren bereits `statamic-marketing`, das sie
    // als „Tagen"/„Stunden"/„Minuten" fuehrt — als Teil eines Satzes richtig,
    // als Beschriftung einer Auswahl falsch. Wer hier dieselben Schluessel mit
    // „Tage" belegt, dreht sie dem Nachbarn im ganzen CP um.
    //
    // Die Hausregel dafuer steht im Test: den QUELLSTRING eindeutig machen,
    // nicht die fremde Uebersetzung ueberschreiben.
    { value: 'minutes', label: __('Minutes') },
    { value: 'hours', label: __('Hours') },
    { value: 'days', label: __('Days') },
]);

const afterOptions = computed(() => [
    { value: '', label: __('First — right after the trigger') },
    ...mails.value.map((mail) => ({
        value: mail.node_key,
        label: __('After “:label”', { label: mail.label || mail.node_key }),
    })),
]);

function openAddForm() {
    form.value = {
        type: props.types.length === 1 ? props.types[0].handle : '',
        label: '',
        amount: 0,
        unit: 'days',
        after: mails.value.length ? mails.value[mails.value.length - 1].node_key : '',
    };
    adding.value = true;
}

function submitAdd() {
    if (! form.value.type) return;

    adding.value = false;

    emit('insert', {
        type: form.value.type,
        label: form.value.label || null,
        after: form.value.after || null,
        delay: {
            amount: Math.max(0, Math.floor(Number(form.value.amount) || 0)),
            unit: form.value.unit,
        },
    });
}
</script>
