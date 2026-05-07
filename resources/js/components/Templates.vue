<template>
    <div class="sa-templates">
        <header class="sa-list__header">
            <div>
                <h1 class="sa-list__title">Templates</h1>
                <p class="sa-list__subtitle">
                    Templates are copied into a new automation when you install one.
                    Editing the copy never affects the template.
                </p>
            </div>
        </header>

        <LoadingSpinner v-if="loading" label="Loading templates…" />
        <ErrorMessage v-else-if="error" :message="error" level="error" title="Couldn't load templates">
            <template #actions>
                <button type="button" class="sa-btn" @click="load">Retry</button>
            </template>
        </ErrorMessage>
        <EmptyState
            v-else-if="!items.length"
            title="No templates installed"
            message="Templates ship with the addon — try reinstalling or check the logs."
        />

        <div v-else class="sa-templates__grid">
            <article v-for="tpl in items" :key="tpl.handle" class="sa-templates__card">
                <header class="sa-templates__card-header">
                    <h2 class="sa-templates__card-title">{{ tpl.name }}</h2>
                    <span v-for="req in tpl.requires ?? []" :key="req" class="sa-pill sa-pill--gray">requires {{ req }}</span>
                </header>
                <p class="sa-templates__card-description">{{ tpl.description }}</p>
                <ul class="sa-templates__card-nodes">
                    <li v-for="node in tpl.nodes ?? []" :key="node.node_key">
                        <code>{{ node.type }}</code> — {{ node.node_key }}
                    </li>
                </ul>
                <button
                    type="button"
                    class="sa-btn"
                    :disabled="installing === tpl.handle"
                    @click="install(tpl)"
                >
                    {{ installing === tpl.handle ? 'Installing…' : 'Install template' }}
                </button>
            </article>
        </div>

        <Toast v-if="toastState.message" :key="toastState.seq" :message="toastState.message" :level="toastState.level" />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api/client.js';
import EmptyState from './ui/EmptyState.vue';
import LoadingSpinner from './ui/LoadingSpinner.vue';
import ErrorMessage from './ui/ErrorMessage.vue';
import Toast from './ui/Toast.vue';
import { toast, useToastState } from '../composables/useToast.js';

const items = ref([]);
const loading = ref(false);
const error = ref(null);
const installing = ref(null);
const toastState = useToastState();

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        items.value = await api.templates.list();
    } catch (e) {
        error.value = e?.response?.data?.message ?? 'Couldn\'t load templates.';
    } finally {
        loading.value = false;
    }
}

async function install(tpl) {
    installing.value = tpl.handle;
    try {
        const automation = await api.templates.install(tpl.handle);
        toast.success(`Installed “${tpl.name}”`);
        if (automation?.id) {
            window.location.href = `${automation.id}`;
        }
    } catch (e) {
        toast.error(e?.response?.data?.message ?? 'Install failed.');
    } finally {
        installing.value = null;
    }
}
</script>
