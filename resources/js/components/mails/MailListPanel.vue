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
     * Presentational on purpose: every mutation leaves as an event, so the page
     * owns the HTTP and this component can be mounted in a test with nothing but
     * props. -->
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
             (Support\RunStats), so it costs nothing to show here. -->
        <div v-if="stats" class="flex flex-wrap gap-2" data-mail-list-stats>
            <Badge :prepend="__('Enrolled')" :text="String(stats.enrolled ?? 0)" />
            <Badge color="blue" :prepend="__('In progress')" :text="String(stats.in_progress ?? 0)" />
            <Badge color="green" :prepend="__('Completed')" :text="String(stats.completed ?? 0)" />
            <Badge color="amber" :prepend="__('Exited')" :text="String(stats.exited ?? 0)" />
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

        <CardList v-else>
            <CardListItem
                v-for="(mail, index) in mails"
                :key="mail.node_key"
                :data-mail-row="mail.node_key"
            >
                <div class="flex w-full items-start gap-3">
                    <span class="w-6 shrink-0 pt-0.5 text-sm tabular-nums text-gray-500">{{ index + 1 }}</span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- The name opens the mail. A button rather than
                                 the whole row: the row already carries three
                                 controls of its own, and a click target that
                                 swallows them would take the reorder arrows
                                 with it. A button also reaches the keyboard,
                                 which a clickable div does not. -->
                            <button
                                type="button"
                                class="cursor-pointer truncate text-start font-medium text-gray-900 hover:underline dark:text-gray-100"
                                :data-mail-open="mail.node_key"
                                @click="$emit('open', mail)"
                            >
                                {{ mail.label || mail.node_key }}
                            </button>
                            <Badge v-if="mail.reference" :text="mail.reference" />
                            <Badge
                                v-if="mail.conditional"
                                color="amber"
                                icon="git"
                                :text="__('Conditional')"
                                :data-mail-conditional="mail.node_key"
                            />
                            <Badge v-if="mail.disabled" :text="__('Disabled')" />
                        </div>

                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" :data-mail-delay="mail.node_key">
                            {{ delayLabel(mail, index) }}
                        </p>

                        <p v-if="mail.conditional" class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Only some readers reach this mail: :condition', { condition: mail.condition ?? '' }) }}
                        </p>

                        <p v-if="mail.also_runs?.length" class="mt-1 text-sm text-gray-500">
                            {{ __('Also runs in this gap: :steps', { steps: alsoRuns(mail) }) }}
                        </p>
                    </div>

                    <!-- Reordering is two buttons, not a drag handle: a drag is
                         unreachable from a keyboard and silent to a screen
                         reader, and this list is short by nature. -->
                    <div v-if="canMutate" class="flex shrink-0 items-center gap-1">
                        <Button
                            :ref="(el) => setMoveRef(`${mail.node_key}:up`, el)"
                            variant="ghost"
                            size="sm"
                            icon-only
                            icon="arrow-up"
                            :aria-label="__('Move “:label” one place earlier', { label: mail.label || mail.node_key })"
                            :disabled="index === 0"
                            @click="move(index, -1)"
                        />
                        <Button
                            :ref="(el) => setMoveRef(`${mail.node_key}:down`, el)"
                            variant="ghost"
                            size="sm"
                            icon-only
                            icon="arrow-down"
                            :aria-label="__('Move “:label” one place later', { label: mail.label || mail.node_key })"
                            :disabled="index === mails.length - 1"
                            @click="move(index, 1)"
                        />
                        <!-- `request-remove`, not `remove`: nothing is deleted
                             until the page has confirmed it. The confirmation
                             lives next to the request that carries it out, so
                             the two can never drift apart. -->
                        <Button
                            variant="ghost"
                            size="sm"
                            icon-only
                            icon="trash"
                            :aria-label="__('Delete “:label”', { label: mail.label || mail.node_key })"
                            @click="$emit('request-remove', mail)"
                        />
                    </div>
                </div>
            </CardListItem>
        </CardList>

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
import { computed, nextTick, ref, watch } from 'vue';
import {
    Alert,
    Badge,
    Button,
    Card,
    CardList,
    CardListItem,
    Description,
    Field,
    Heading,
    Input,
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
});

const emit = defineEmits(['reorder', 'request-remove', 'insert', 'open-flow', 'open']);

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

// ---------- Reordering ----------

const moveRefs = new Map();
const focusAfterMove = ref(null);

// Vue hands `null` when the row unmounts. It is stored as-is rather than
// dropped: the focus restore below already guards a missing element, and a Map
// that is one row long has nothing worth pruning.
function setMoveRef(key, el) {
    moveRefs.set(key, el);
}

function move(index, delta) {
    const order = movedOrder(mails.value.map((mail) => mail.node_key), index, delta);

    if (! order) return;

    // Keep the keyboard where the user left it: after a move the row has a new
    // position, and focus must travel with it instead of dropping to the body.
    focusAfterMove.value = `${mails.value[index].node_key}:${delta < 0 ? 'up' : 'down'}`;

    emit('reorder', order);
}

watch(
    () => mails.value.map((mail) => mail.node_key).join('>'),
    async () => {
        const key = focusAfterMove.value;
        if (! key) return;
        focusAfterMove.value = null;

        await nextTick();

        const button = moveRefs.get(key);
        const el = button?.$el ?? button;
        el?.focus?.();
    },
);

// ---------- Inserting ----------

const adding = ref(false);
const form = ref({ type: '', label: '', amount: 0, unit: 'days', after: '' });

const typeOptions = computed(() =>
    props.types.map((type) => ({ value: type.handle, label: type.label || type.handle })),
);

const unitOptions = computed(() => [
    { value: 'minutes', label: __('minutes') },
    { value: 'hours', label: __('hours') },
    { value: 'days', label: __('days') },
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
