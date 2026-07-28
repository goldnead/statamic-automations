<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Panel,
    CodeEditor,
    Alert,
    Badge,
} from '@statamic/cms/ui';
import axios from 'axios';

const props = defineProps({
    title: { type: String, required: true },
    importUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
});

const text = ref('');
const dragging = ref(false);
const submitting = ref(false);
const result = ref(null);

const parsed = computed(() => {
    try {
        return text.value ? JSON.parse(text.value) : null;
    } catch {
        return null;
    }
});

const valid = computed(() => parsed.value !== null && typeof parsed.value === 'object');

async function readFile(file) {
    text.value = await file.text();
}

function onDrop(event) {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) readFile(file);
}

function onSelect(event) {
    const file = event.target.files?.[0];
    if (file) readFile(file);
}

async function submit() {
    if (!valid.value) return;
    submitting.value = true;
    try {
        const { data } = await axios.post(props.importUrl, { payload: parsed.value });
        result.value = data;
        window.Statamic?.$toast?.success?.(__('Imported.'));
    } catch (e) {
        window.Statamic?.$toast?.error?.(e?.response?.data?.message || __('Import failed.'));
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-3xl mx-auto">
        <Header :title="title" icon="upload">
            <Link :href="indexUrl" class="text-sm text-gray-500 hover:text-blue-500">
                ← {{ __('Back to automations') }}
            </Link>
        </Header>

        <Panel :heading="__('Drop a file')">
            <label
                class="block rounded-md border-2 border-dashed p-8 text-center cursor-pointer transition-colors"
                :class="dragging
                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30'
                    : 'border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-900'"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <input type="file" accept="application/json" class="hidden" @change="onSelect">
                <p class="text-sm">
                    {{ __('Drop a') }} <strong>.json</strong> {{ __('file here, or click to browse.') }}
                </p>
            </label>
        </Panel>

        <Panel :heading="__('Or paste JSON')" class="mt-4">
            <CodeEditor v-model="text" mode="json" :placeholder="__('Paste exported automation JSON…')" />
            <p v-if="text && !valid" class="text-xs text-red-600 dark:text-red-400 mt-2">
                {{ __('Invalid JSON.') }}
            </p>
        </Panel>

        <div class="mt-4 flex items-center gap-2">
            <Button
                :text="submitting ? __('Importing…') : __('Import')"
                variant="primary"
                :disabled="!valid || submitting"
                @click="submit"
            />
            <Button :text="__('Reset')" variant="ghost" @click="text = ''; result = null" />
        </div>

        <Alert v-if="result" variant="success" class="mt-4">
            <strong>{{ __('Imported.') }}</strong>
            <div v-if="result.meta?.warnings?.length" class="mt-2">
                <p class="text-xs uppercase tracking-wider text-amber-700 dark:text-amber-400">{{ __('Warnings') }}</p>
                <ul class="mt-1 ml-4 list-disc text-sm">
                    <li v-for="(w, i) in result.meta.warnings" :key="i">{{ w }}</li>
                </ul>
            </div>
            <div v-if="result.meta?.missing_integrations?.length" class="mt-2">
                <p class="text-xs uppercase tracking-wider text-red-700 dark:text-red-400">{{ __('Missing integrations') }}</p>
                <div class="flex flex-wrap gap-1 mt-1">
                    <Badge v-for="m in result.meta.missing_integrations" :key="m" color="red" :text="m" />
                </div>
            </div>
        </Alert>
    </div>
</template>
