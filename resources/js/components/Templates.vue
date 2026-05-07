<template>
    <div class="sa-templates">
        <header class="sa-list__header">
            <h1 class="sa-list__title">Templates</h1>
            <p class="sa-list__subtitle">
                Templates are copied into a new automation when you install one.
                Editing the copy never affects the template.
            </p>
        </header>

        <div v-if="loading" class="sa-list__loading">Loading…</div>

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
                    @click="install(tpl.handle)"
                >
                    {{ installing === tpl.handle ? 'Installing…' : 'Install template' }}
                </button>
            </article>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api/client.js';

const items = ref([]);
const loading = ref(false);
const installing = ref(null);

onMounted(load);

async function load() {
    loading.value = true;
    try {
        items.value = await api.templates.list();
    } finally {
        loading.value = false;
    }
}

async function install(handle) {
    installing.value = handle;
    try {
        const automation = await api.templates.install(handle);
        if (automation?.id) {
            window.location.href = `${automation.id}`;
        }
    } finally {
        installing.value = null;
    }
}
</script>
