<template>
    <div class="sa-token-picker">
        <button
            type="button"
            class="sa-token-picker__trigger"
            @click="open = !open"
        >Insert variable ▾</button>

        <div v-if="open" class="sa-token-picker__panel">
            <div v-if="loading" class="sa-token-picker__loading">Loading…</div>
            <ul v-else-if="entries.length" class="sa-token-picker__list">
                <li
                    v-for="entry in entries"
                    :key="entry.path"
                    class="sa-token-picker__entry"
                    @click="pick(entry)"
                >
                    <span class="sa-token-picker__path">{{ entry.path }}</span>
                    <span v-if="entry.type" class="sa-token-picker__type">{{ entry.type }}</span>
                </li>
            </ul>
            <p v-else class="sa-token-picker__empty">No variables available — pick a trigger first.</p>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { api } from '../api/client.js';

const props = defineProps({
    source: { type: String, default: null },
});

const emit = defineEmits(['pick']);

const entries = ref([]);
const open = ref(false);
const loading = ref(false);

watch(() => props.source, load, { immediate: true });

async function load() {
    if (!props.source) {
        entries.value = [];
        return;
    }
    loading.value = true;
    try {
        const schema = await api.nodes.contextSchema(props.source);
        entries.value = flatten(schema);
    } catch {
        entries.value = [];
    } finally {
        loading.value = false;
    }
}

function flatten(obj, prefix = '') {
    if (typeof obj !== 'object' || obj === null) return [];
    const out = [];
    for (const [key, value] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
            out.push(...flatten(value, path));
        } else {
            out.push({ path, type: String(value) });
        }
    }
    return out;
}

function pick(entry) {
    emit('pick', `{{ ${entry.path} }}`);
    open.value = false;
}
</script>
