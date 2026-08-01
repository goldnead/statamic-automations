<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Badge,
    Icon,
    EmptyStateMenu,
    EmptyStateItem,
} from '@statamic/cms/ui';
import axios from 'axios';
import { firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    title: { type: String, required: true },
    templates: { type: Array, required: true },
    automationsUrl: { type: String, required: true },
    canCreate: { type: Boolean, default: false },
});

const installing = ref(null);

async function install(template) {
    installing.value = template.handle;
    try {
        const { data } = await axios.post(template.install_url);
        const created = data?.data ?? data;
        window.Statamic?.$toast?.success?.(__('Template installed.'));
        // edit_url comes from the server (AutomationResource). Deriving it from
        // location.pathname worked only by accident of the route layout.
        router.visit(created.edit_url);
    } catch (e) {
        window.Statamic?.$toast?.error?.(firstMessage(e, __('Install failed.')));
    } finally {
        installing.value = null;
    }
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto">
        <Header :title="title" icon="duplicate" />

        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 max-w-2xl">
            {{ __('Templates are pre-built automations you can install with one click. Each template is copied into a new automation that you can freely edit afterwards — addon updates do not silently change your installed copies.') }}
        </p>

        <EmptyStateMenu v-if="templates.length === 0" :heading="__('No templates available')">
            <EmptyStateItem
                :href="automationsUrl"
                icon="workflow"
                :heading="__('Build one from scratch')"
                :description="__('Every built-in template is gated behind the integration it needs. With none installed, the catalog is empty.')"
            />
        </EmptyStateMenu>

        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <article
                v-for="template in templates"
                :key="template.handle"
                class="rounded-md border border-gray-200 dark:border-gray-700 bg-content-bg p-4 flex flex-col gap-2"
            >
                <header class="flex items-start justify-between gap-2">
                    <h3 class="text-base font-semibold m-0">{{ template.name }}</h3>
                    <Badge
                        v-if="!template.available"
                        color="amber"
                        :text="__('Requires :req', { req: template.missing_integrations.join(', ') })"
                    />
                </header>
                <p class="text-sm text-gray-600 dark:text-gray-400 flex-1">{{ template.description }}</p>
                <footer class="flex items-center justify-between gap-2">
                    <span class="text-2xs text-gray-500">{{ __(':n nodes', { n: template.node_count }) }}</span>
                    <Button
                        :text="installing === template.handle ? __('Installing…') : __('Install')"
                        variant="primary"
                        size="sm"
                        :disabled="!canCreate || !template.available || installing === template.handle"
                        @click="install(template)"
                    />
                </footer>
            </article>
        </div>
    </div>
</template>
