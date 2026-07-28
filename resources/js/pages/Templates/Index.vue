<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Badge,
    Icon,
} from '@statamic/cms/ui';
import axios from 'axios';

const props = defineProps({
    title: { type: String, required: true },
    templates: { type: Array, required: true },
    canCreate: { type: Boolean, default: false },
});

const installing = ref(null);

async function install(template) {
    installing.value = template.handle;
    try {
        const { data } = await axios.post(template.install_url);
        const created = data?.data ?? data;
        window.Statamic?.$toast?.success?.(__('Template installed.'));
        router.visit(window.location.pathname.replace('/templates', '/automations/' + created.id + '/edit'));
    } catch (e) {
        window.Statamic?.$toast?.error?.(e?.response?.data?.message || __('Install failed.'));
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <article
                v-for="template in templates"
                :key="template.handle"
                class="rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 flex flex-col gap-2"
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
