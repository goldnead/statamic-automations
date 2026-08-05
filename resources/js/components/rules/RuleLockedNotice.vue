<template>
    <!-- Why the rule is readable but not editable here.
     *
     * "This automation is not a rule" is the sentence this component exists to
     * avoid: an editor cannot act on it. Sequence\RuleShape already produces
     * reasons that name the thing in the way — a second mail, a delay between
     * the trigger and the mail, a fork — so they are printed as they came,
     * followed by the one action that always applies. Restating them in the
     * component's own words would give the same fact two wordings that drift.
     *
     * The row above stays on screen. A refusal to edit is not a reason to stop
     * answering "which mail goes out when this fires". -->
    <Alert variant="warning" data-rule-locked>
        <p class="font-medium">
            {{ __('This rule can be read but not changed here.') }}
        </p>
        <p class="mt-1 text-sm">
            {{ __('A rule is one trigger and one mail, with nothing in between. That is what makes it fit on a row:') }}
        </p>

        <ul class="mt-3 ms-4 list-disc space-y-1 text-sm">
            <li v-for="(reason, index) in reasons" :key="index" data-rule-reason>
                {{ reason }}
            </li>
        </ul>

        <Button
            class="mt-4"
            size="sm"
            icon="workflow"
            :text="__('Open it on the canvas')"
            data-rule-open-flow
            @click="$emit('open-flow')"
        />
    </Alert>
</template>

<script setup>
import { Alert, Button } from '@statamic/cms/ui';

defineProps({
    /** The `reasons` array of the rule projection, verbatim. */
    reasons: { type: Array, default: () => [] },
});

defineEmits(['open-flow']);
</script>
