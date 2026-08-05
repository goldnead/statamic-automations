<template>
    <!-- One automation, as the sentence it is: "when X happens, send Y to Z".
     *
     * **This is the rule, not a picture of the automation.** Same split as the
     * mail list: the sentence renders for every shape, because a reader looking
     * for "which mail goes out when the contact form is submitted" needs an
     * answer whether or not the flow behind it grew a delay. What the shape
     * decides (Sequence\RuleShape) is whether the fields below it are offered.
     *
     * Presentational on purpose: a save leaves as an event carrying only what
     * changed, so the page owns the HTTP and this component can be mounted in a
     * test with nothing but props. -->
    <Card :data-rule-row="rule.handle">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-base text-gray-900 dark:text-gray-100">
                    <span class="text-gray-500">{{ __('When') }}</span>
                    <span class="font-medium">{{ triggerLabel }}</span>
                    <span class="text-gray-500">{{ __('happens, send') }}</span>
                    <span class="font-medium">{{ mailLabel }}</span>
                    <span class="text-gray-500">{{ __('to') }}</span>
                    <span class="font-medium">{{ recipientLabel }}</span>
                </p>
                <p class="mt-1 text-sm text-gray-500">{{ rule.name }}</p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Badge
                    :color="rule.enabled ? 'green' : 'gray'"
                    :text="rule.enabled ? __('On') : __('Off')"
                />
                <Badge
                    v-if="rule.dispatch_mode === 'sync'"
                    color="amber"
                    :text="__('Sends immediately')"
                    data-rule-sync-badge
                />
                <Badge v-if="rule.mail?.reference" :text="rule.mail.reference" />
            </div>
        </div>

        <!-- What the last few runs did. A rule that reads perfectly and fails
             every time is the case this row exists to make visible. -->
        <div v-if="rule.recent_runs?.length" class="mt-3 flex flex-wrap items-center gap-2" data-rule-runs>
            <span class="text-sm text-gray-500">{{ __('Last runs') }}</span>
            <Badge
                v-for="run in rule.recent_runs"
                :key="run.id"
                :color="runColor(run.status)"
                :text="run.status"
            />
        </div>

        <RuleLockedNotice
            v-if="!rule.editable"
            class="mt-4"
            :reasons="rule.reasons ?? []"
            @open-flow="$emit('open-flow', rule)"
        />

        <Alert v-else-if="!canEdit" variant="default" class="mt-4" data-rule-readonly>
            {{ __('You can read this rule. Changing it needs the "edit automations" permission.') }}
        </Alert>

        <div v-else class="mt-4 space-y-4" data-rule-form>
            <Field :label="__('Recipient')" :instructions="__('Where this mail goes. Tokens resolve against the run, e.g. {{ subscriber.email }}.')">
                <Input
                    :model-value="form.recipient"
                    data-rule-recipient
                    @update:model-value="form.recipient = $event"
                />
            </Field>

            <Field
                v-if="templateOptions.length"
                :label="__('Template')"
                :instructions="__('The managed template this mail sends. Empty sends the plain body the step carries.')"
            >
                <Select
                    :model-value="form.template"
                    :options="templateOptions"
                    data-rule-template
                    @update:model-value="form.template = $event"
                />
            </Field>

            <Field :label="__('Status')">
                <Switch
                    :model-value="form.enabled"
                    :label="__('This rule runs when its trigger fires')"
                    data-rule-enabled
                    @update:model-value="form.enabled = $event"
                />
            </Field>

            <Field :label="__('When this runs')">
                <Select
                    :model-value="form.dispatch_mode"
                    :options="dispatchOptions"
                    data-rule-dispatch
                    @update:model-value="form.dispatch_mode = $event"
                />
                <!-- What immediate sending actually costs, in the one place
                     somebody chooses it. It is TIME, not error handling: the
                     runner writes a failed run and returns normally either way
                     (Engine\WorkflowRunner), so a warning about errors reaching
                     the request would send somebody debugging a request that
                     never failed. What changes is that the request waits. -->
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-rule-dispatch-help>
                    {{ __('In the background is right for almost everything. Immediately is for a mail that has to be gone before the page finishes loading — and it means the request waits for the whole run, so the page takes as long as the automation does.') }}
                </p>
            </Field>

            <div class="flex items-center gap-2">
                <Button
                    variant="primary"
                    size="sm"
                    :text="busy ? __('Saving…') : __('Save')"
                    :disabled="!hasChanges || busy"
                    data-rule-save
                    @click="save"
                />
                <Button
                    size="sm"
                    variant="ghost"
                    icon="workflow"
                    :text="__('Open on the canvas')"
                    data-rule-open-flow
                    @click="$emit('open-flow', rule)"
                />
            </div>
        </div>
    </Card>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Alert, Badge, Button, Card, Field, Input, Select, Switch } from '@statamic/cms/ui';

import RuleLockedNotice from './RuleLockedNotice.vue';

const props = defineProps({
    /** One `Sequence\RuleProjection` row, plus `edit_url` and `template_options`. */
    rule: { type: Object, required: true },
    /** Whether the user holds "edit automations". */
    canEdit: { type: Boolean, default: true },
    /** A write is in flight. */
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'open-flow']);

const blank = () => ({
    recipient: props.rule.recipient ?? '',
    template: props.rule.template ?? '',
    enabled: Boolean(props.rule.enabled),
    dispatch_mode: props.rule.dispatch_mode ?? 'async',
});

const form = ref(blank());

// The row is re-rendered from the server's answer after every save, and the
// form follows it. Without this a second edit would send the first edit's
// values again, including any the server had normalised away.
watch(() => props.rule, () => { form.value = blank(); }, { deep: true });

const triggerLabel = computed(() => props.rule.trigger?.label || props.rule.trigger?.handle || __('something'));
const mailLabel = computed(() => props.rule.mail?.label || props.rule.mail?.reference || __('a mail'));
const recipientLabel = computed(() => props.rule.recipient || __('nobody yet'));

const templateOptions = computed(() => {
    const options = props.rule.template_options ?? [];

    if (! options.length) return [];

    // An empty choice, because clearing the template is a real edit: the step
    // then sends the plain body it carries.
    return [{ value: '', label: __('No template — send the plain body') }, ...options];
});

const dispatchOptions = computed(() => [
    { value: 'async', label: __('In the background (queued)') },
    { value: 'sync', label: __('Immediately, inside the request') },
]);

/**
 * Only what the person actually changed.
 *
 * A payload carrying every field would make a status toggle on one row
 * overwrite a template somebody else had just picked, and would push an empty
 * recipient at a node that never offered one.
 */
const changes = computed(() => {
    const current = blank();
    const changed = {};

    for (const key of Object.keys(current)) {
        if (form.value[key] !== current[key]) changed[key] = form.value[key];
    }

    return changed;
});

const hasChanges = computed(() => Object.keys(changes.value).length > 0);

function runColor(status) {
    return { success: 'green', failed: 'red', running: 'blue' }[status] ?? 'gray';
}

function save() {
    if (! hasChanges.value || props.busy) return;

    emit('save', { ...changes.value });
}
</script>
