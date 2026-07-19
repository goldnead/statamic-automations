<template>
    <Modal
        :open="open"
        :title="__('E-Mail-Vorlage wählen')"
        icon="layout-list"
        class="max-w-4xl!"
        @update:open="$emit('update:open', $event)"
    >
        <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,17rem)_1fr] gap-4 -mx-1">
            <!-- LEFT · searchable, keyboard-navigable list -->
            <div class="flex flex-col min-h-0 px-1">
                <Input
                    v-model="query"
                    type="search"
                    :placeholder="__('Vorlagen durchsuchen…')"
                    class="mb-2"
                    @keydown.down.prevent="moveHighlight(1)"
                    @keydown.up.prevent="moveHighlight(-1)"
                    @keydown.enter.prevent="confirmSelection"
                />

                <div
                    ref="listEl"
                    tabindex="0"
                    role="listbox"
                    :aria-activedescendant="highlighted ? `sa-tpl-${highlighted}` : undefined"
                    class="flex-1 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-800 divide-y divide-gray-100 dark:divide-gray-800 max-h-[46vh] outline-none focus:ring-2 focus:ring-primary/40"
                    @keydown.down.prevent="moveHighlight(1)"
                    @keydown.up.prevent="moveHighlight(-1)"
                    @keydown.enter.prevent="confirmSelection"
                >
                    <p v-if="listLoading" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Vorlagen werden geladen…') }}
                    </p>
                    <p v-else-if="!filtered.length" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ query ? __('Keine Treffer.') : __('Keine Vorlagen vorhanden.') }}
                    </p>

                    <button
                        v-for="tpl in filtered"
                        :id="`sa-tpl-${tpl.slug}`"
                        :key="tpl.slug"
                        type="button"
                        role="option"
                        :aria-selected="tpl.slug === highlighted"
                        class="block w-full text-left px-3 py-2.5 transition-colors focus:outline-none"
                        :class="tpl.slug === highlighted
                            ? 'bg-primary/10 dark:bg-primary/20'
                            : 'hover:bg-gray-50 dark:hover:bg-gray-800/60'"
                        @click="highlighted = tpl.slug"
                        @dblclick="confirmSelection"
                    >
                        <span class="flex items-center gap-2">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ tpl.title || tpl.slug }}
                            </span>
                            <Icon
                                v-if="tpl.slug === modelValue"
                                name="checkmark"
                                class="size-3.5 shrink-0 text-primary"
                            />
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ tpl.subject || __('(kein Betreff)') }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- RIGHT · live render of the highlighted template -->
            <div class="min-w-0 px-1">
                <div
                    v-if="previewLoading"
                    class="flex flex-col items-center justify-center gap-2 h-full min-h-72 text-sm text-gray-500 dark:text-gray-400"
                >
                    <Icon name="loading" class="size-5 animate-spin" />
                    <span>{{ __('Vorschau wird geladen…') }}</span>
                </div>

                <div
                    v-else-if="previewError"
                    class="flex flex-col items-center justify-center gap-2 h-full min-h-72 text-sm text-red-600 dark:text-red-400"
                >
                    <Icon name="warning-diamond" class="size-5" />
                    <span>{{ __('Vorschau nicht verfügbar.') }}</span>
                </div>

                <div v-else-if="preview" class="flex flex-col gap-2">
                    <header class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900">
                        <p class="m-0 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {{ __('Betreff') }}
                        </p>
                        <p class="m-0 text-sm font-semibold leading-snug text-gray-900 dark:text-gray-100 truncate">
                            {{ preview.subject || __('(kein Betreff)') }}
                        </p>
                        <p v-if="preview.preview" class="m-0 mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
                            {{ preview.preview }}
                        </p>
                    </header>

                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800 bg-white shadow-sm">
                        <iframe
                            :srcdoc="preview.html"
                            sandbox="allow-same-origin"
                            class="block w-full h-[40vh] min-h-72 border-0 bg-white"
                            :title="__('Vorschau der Vorlage')"
                            loading="lazy"
                        />
                    </div>
                </div>

                <div v-else class="flex items-center justify-center h-full min-h-72 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ __('Vorlage links auswählen, um die Vorschau zu sehen.') }}</span>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-2 px-4 py-3">
                <Button variant="ghost" :text="__('Abbrechen')" @click="$emit('update:open', false)" />
                <Button
                    variant="primary"
                    icon="checkmark"
                    :text="__('Diese Vorlage verwenden')"
                    :disabled="!highlighted"
                    @click="confirmSelection"
                />
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Modal, Button, Input, Icon } from '@statamic/cms/ui';
import { useEmailTemplateList, useEmailTemplatePreview } from '../../composables/useEmailTemplatePreview.js';

/**
 * Master-detail template picker. Left: a searchable, keyboard-navigable list of
 * managed templates (title + subject). Right: a live sandboxed-iframe render of
 * the highlighted template (same endpoint as EmailPreviewModal, cached per slug).
 * Confirming (button / Enter / double-click) emits the chosen slug and closes.
 */
const props = defineProps({
    /** Controlled open state (v-model:open). */
    open: { type: Boolean, default: false },
    /** Currently selected slug (marked with a check in the list). */
    modelValue: { type: String, default: null },
    /** Addon CP API base (e.g. `.../automations/api`). */
    apiBase: { type: String, required: true },
});

const emit = defineEmits(['update:open', 'select']);

const query = ref('');
const highlighted = ref(null);
const listEl = ref(null);

const { templates, loading: listLoading, load: loadList } = useEmailTemplateList(props.apiBase);
const { preview, loading: previewLoading, error: previewError, load: loadPreview } = useEmailTemplatePreview(props.apiBase);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return templates.value;
    return templates.value.filter((t) =>
        `${t.title ?? ''} ${t.subject ?? ''} ${t.slug ?? ''}`.toLowerCase().includes(q),
    );
});

// Populate the list on open and seed the highlight from the current value (or
// the first template), so the right pane shows something immediately.
watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen) return;
        query.value = '';
        await loadList();
        const exists = templates.value.some((t) => t.slug === props.modelValue);
        highlighted.value = exists ? props.modelValue : (templates.value[0]?.slug ?? null);
    },
    { immediate: true },
);

// Keep the highlight valid as the search narrows the list.
watch(filtered, (list) => {
    if (!list.some((t) => t.slug === highlighted.value)) {
        highlighted.value = list[0]?.slug ?? null;
    }
});

// Debounced preview fetch for whatever is highlighted (cached ⇒ usually instant).
let debounceId = null;
watch(
    highlighted,
    (slug) => {
        clearTimeout(debounceId);
        if (!slug) return;
        debounceId = setTimeout(() => loadPreview(slug), 120);
    },
    { immediate: true },
);

function moveHighlight(delta) {
    const list = filtered.value;
    if (!list.length) return;
    const idx = list.findIndex((t) => t.slug === highlighted.value);
    const next = Math.min(list.length - 1, Math.max(0, (idx === -1 ? 0 : idx) + delta));
    highlighted.value = list[next].slug;
    // Keep the active row in view.
    requestAnimationFrame(() => {
        listEl.value?.querySelector(`#sa-tpl-${CSS.escape(highlighted.value)}`)?.scrollIntoView({ block: 'nearest' });
    });
}

function confirmSelection() {
    if (!highlighted.value) return;
    emit('select', highlighted.value);
    emit('update:open', false);
}
</script>
