<script setup>
/**
 * Mail rules — the automations that are one trigger and one mail, as sentences.
 *
 * The page owns the HTTP; each row (RulePanel) is presentational and emits what
 * changed. A refused write answers 422 with the shape's reasons AND the row as
 * it stands, so the screen updates to the truth instead of keeping a value the
 * server rejected.
 */
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, EmptyStateMenu, EmptyStateItem } from '@statamic/cms/ui';
import axios from 'axios';

import RulePanel from '../../components/rules/RulePanel.vue';
import { firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    title: { type: String, required: true },
    rules: { type: Array, required: true },
    automationsUrl: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});

const rows = ref([...props.rules]);
const saving = ref(null);

function replace(handle, row) {
    rows.value = rows.value.map((existing) => (
        existing.handle === handle
            // The server's answer carries the projection only; the two urls and
            // the template choices were added by the page controller and are not
            // part of it, so they are kept from the row that was on screen.
            ? { ...existing, ...row }
            : existing
    ));
}

async function save(rule, payload) {
    saving.value = rule.handle;

    try {
        const { data } = await axios.patch(rule.update_url, payload);
        replace(rule.handle, data);
        window.Statamic?.$toast?.success?.(__('Rule saved.'));
    } catch (e) {
        // A 422 carries the row as the server has it. Showing that instead of
        // the rejected form is the difference between "it did not save" and a
        // screen that quietly disagrees with the database.
        const row = e?.response?.data?.rule;
        if (row) replace(rule.handle, row);

        window.Statamic?.$toast?.error?.(firstMessage(e, __('Saving the rule failed.')));
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto">
        <Header :title="title" icon="mail" />

        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 max-w-2xl">
            {{ __('An automation that does one thing — when something happens, send one mail — reads as a sentence, and can be changed from it. Everything with more than one mail in it lives on its own page or on the canvas.') }}
        </p>

        <EmptyStateMenu v-if="rows.length === 0" :heading="__('No rules yet')">
            <EmptyStateItem
                :href="automationsUrl"
                icon="workflow"
                :heading="__('Build one on the canvas')"
                :description="__('A trigger, one mail step, one connection between them. It appears here as soon as it exists.')"
            />
        </EmptyStateMenu>

        <div v-else class="space-y-4">
            <RulePanel
                v-for="rule in rows"
                :key="rule.handle"
                :rule="rule"
                :can-edit="canEdit"
                :busy="saving === rule.handle"
                @save="(payload) => save(rule, payload)"
                @open-flow="router.visit(rule.edit_url)"
            />
        </div>
    </div>
</template>
