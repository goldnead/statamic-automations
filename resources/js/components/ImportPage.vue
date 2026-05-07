<template>
    <div class="sa-list">
        <header class="sa-list__header">
            <h1 class="sa-list__title">Import automation</h1>
            <p class="sa-list__subtitle">Drop a JSON export here or paste it below to recreate the automation.</p>
        </header>

        <div class="sa-import">
            <label
                class="sa-import__dropzone"
                :class="{ 'sa-import__dropzone--dragging': dragging }"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <input type="file" accept=".json,application/json" class="sa-import__file" @change="onFileChange" />
                <span class="sa-import__hint">
                    <strong>Drop a .json file</strong> or <em>click to browse</em>
                </span>
            </label>

            <details class="sa-import__paste">
                <summary>…or paste JSON</summary>
                <textarea
                    v-model="pasteValue"
                    class="sa-field__textarea"
                    rows="10"
                    placeholder='{"schema_version": 1, "automation": {…}, "nodes": […], "edges": […]}'
                ></textarea>
                <button type="button" class="sa-btn" :disabled="!pasteValue || importing" @click="importPaste">
                    {{ importing ? 'Importing…' : 'Import pasted JSON' }}
                </button>
            </details>

            <ErrorMessage v-if="error" :message="error" level="error" />

            <div v-if="result" class="sa-import__result">
                <h3>Imported as “{{ result.data.name }}”</h3>
                <p v-if="result.meta.warnings.length" class="sa-import__warning">
                    <strong>Warnings:</strong>
                    <ul>
                        <li v-for="(warning, i) in result.meta.warnings" :key="i">{{ warning }}</li>
                    </ul>
                </p>
                <a :href="`${result.data.id}`" class="sa-btn">Open imported automation</a>
            </div>
        </div>
    </div>

    <Toast v-if="toastState.message" :key="toastState.seq" :message="toastState.message" :level="toastState.level" />
</template>

<script setup>
import { ref } from 'vue';
import { api } from '../api/client.js';
import ErrorMessage from './ui/ErrorMessage.vue';
import Toast from './ui/Toast.vue';
import { toast, useToastState } from '../composables/useToast.js';

const dragging = ref(false);
const pasteValue = ref('');
const importing = ref(false);
const error = ref(null);
const result = ref(null);
const toastState = useToastState();

async function onFileChange(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    await importFile(file);
}

async function onDrop(event) {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    await importFile(file);
}

async function importFile(file) {
    error.value = null;
    importing.value = true;
    try {
        const text = await file.text();
        await runImport(JSON.parse(text));
    } catch (e) {
        error.value = e?.response?.data?.message ?? e.message ?? 'Import failed.';
        toast.error('Import failed.');
    } finally {
        importing.value = false;
    }
}

async function importPaste() {
    error.value = null;
    importing.value = true;
    try {
        await runImport(JSON.parse(pasteValue.value));
    } catch (e) {
        error.value = e?.response?.data?.message ?? e.message ?? 'Import failed.';
        toast.error('Import failed.');
    } finally {
        importing.value = false;
    }
}

async function runImport(payload) {
    result.value = await api.exports.import(payload);
    toast.success(`Imported "${result.value.data.name}"`);
}
</script>
