<template>
    <!-- Why the list is readable but not editable.
     *
     * "This automation is not linear" is the sentence this component exists to
     * avoid. An editor cannot act on it: it names no node, no condition and no
     * next step. What is shown instead is which of the seven conditions of
     * Sequence\LinearityRule is broken, what that condition means in words, what
     * to do about it, and the server's own sentence underneath as the detail —
     * so the node it names is on screen and not in a log. -->
    <Alert variant="warning" data-mail-list-locked>
        <p class="font-medium">
            {{ __('This list can be read but not rearranged.') }}
        </p>
        <p class="mt-1 text-sm">
            {{ __('Mails can only be inserted, moved and deleted from the list while every mail sits on one straight path. These conditions are not met:') }}
        </p>

        <ul class="mt-3 space-y-3 text-sm">
            <li v-for="group in groups" :key="group.condition ?? 'other'" data-mail-list-reason>
                <strong class="block">{{ headline(group) }}</strong>
                <span v-if="fix(group)" class="block text-gray-600 dark:text-gray-400">{{ fix(group) }}</span>
                <ul class="mt-1 ms-4 list-disc space-y-0.5">
                    <li v-for="(detail, i) in group.details" :key="i">
                        <code class="text-xs">{{ detail }}</code>
                    </li>
                </ul>
            </li>
        </ul>

        <Button
            class="mt-4"
            size="sm"
            icon="workflow"
            :text="__('Rearrange it on the canvas')"
            @click="$emit('open-flow')"
        />
    </Alert>
</template>

<script setup>
import { computed } from 'vue';
import { Alert, Button } from '@statamic/cms/ui';

import { classifyReasons } from '../../support/mailList.js';

const props = defineProps({
    /** The `reasons` array of the mail list projection, verbatim. */
    reasons: { type: Array, default: () => [] },
});

defineEmits(['open-flow']);

const groups = computed(() => classifyReasons(props.reasons));

/** The seven conditions, in the words an editor thinks in. */
function title(condition) {
    return {
        1: __('The automation has exactly one trigger.'),
        2: __('Every step continues into at most one other step.'),
        3: __('No step is reached from more than one place.'),
        4: __('Every connection leaves through the normal output, never a branch output.'),
        5: __('The automation contains no Branch, Switch, Loop or Parallel step.'),
        6: __('Every step can be reached from the trigger.'),
        7: __('The chain never loops back on itself.'),
    }[condition] ?? __('The automation is not a straight line.');
}

/** What to do about it, which is the part a condition alone does not say. */
function fix(group) {
    return {
        1: __('Add a trigger on the canvas, or remove the extra ones.'),
        2: __('Remove the extra connections until the step continues into one step.'),
        3: __('Rewire it so only one path leads into that step.'),
        4: __('A branch output cannot be read as a position in a list. Rework it on the canvas.'),
        5: __('A step that forks by its nature can never be listed in order. Move it on the canvas instead.'),
        6: __('Connect the stranded steps to the chain, or delete them.'),
        7: __('Remove the connection that leads back to an earlier step.'),
    }[group.condition] ?? '';
}

function headline(group) {
    return group.condition === null
        ? title(null)
        : __('Condition :n of 7: :title', { n: group.condition, title: title(group.condition) });
}
</script>
