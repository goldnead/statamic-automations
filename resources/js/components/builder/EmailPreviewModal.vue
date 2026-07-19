<template>
    <Modal
        :open="open"
        :title="__('E-Mail-Vorschau')"
        icon="eye"
        class="max-w-2xl!"
        @update:open="$emit('update:open', $event)"
    >
        <!-- Loading -->
        <div v-if="loading" class="flex flex-col items-center justify-center gap-2 min-h-48 text-sm text-gray-500 dark:text-gray-400">
            <Icon name="loading" class="size-5 animate-spin" />
            <span>{{ __('Vorschau wird geladen…') }}</span>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="flex flex-col items-center justify-center gap-2 min-h-48 text-sm text-center text-red-600 dark:text-red-400">
            <Icon name="warning-diamond" class="size-5" />
            <p class="m-0">{{ __('Die Vorschau konnte nicht geladen werden.') }}</p>
            <Button size="xs" variant="filled" :text="__('Erneut versuchen')" @click="reload" />
        </div>

        <!-- Rendered mail -->
        <div v-else-if="preview" class="flex flex-col gap-3">
            <!-- Faux mail-client header: subject line + preheader -->
            <header class="px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900">
                <p class="m-0 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                    {{ __('Betreff') }}
                </p>
                <h4 class="m-0 text-[0.95rem] font-semibold leading-snug text-gray-900 dark:text-gray-100">
                    {{ preview.subject || __('(kein Betreff)') }}
                </h4>
                <p v-if="preview.preview" class="m-0 mt-1 text-[0.8125rem] text-gray-500 dark:text-gray-400">
                    {{ preview.preview }}
                </p>
            </header>

            <!-- Device-framed render of the branded HTML (sandboxed, no scripts) -->
            <div class="mx-auto w-full max-w-[640px] overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800 bg-white shadow-sm">
                <iframe
                    :srcdoc="preview.html"
                    sandbox="allow-same-origin"
                    class="block w-full h-[48vh] min-h-80 border-0 bg-white"
                    :title="__('E-Mail-Vorschau')"
                    loading="lazy"
                />
            </div>

            <p class="m-0 text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('Vorschau mit Beispieldaten (Max Mustermann). Echte Empfängerdaten werden erst beim Versand eingesetzt.') }}
            </p>
        </div>

        <!-- No slug selected -->
        <div v-else class="flex items-center justify-center min-h-48 text-sm text-gray-500 dark:text-gray-400">
            <span>{{ __('Keine Vorlage ausgewählt.') }}</span>
        </div>
    </Modal>
</template>

<script setup>
import { watch } from 'vue';
import { Modal, Button, Icon } from '@statamic/cms/ui';
import { useEmailTemplatePreview } from '../../composables/useEmailTemplatePreview.js';

/**
 * Renders a single email template to branded HTML inside a sandboxed iframe,
 * framed like a mail client (subject bar + preheader above the render). Fetches
 * `GET {apiBase}/email-templates/preview?slug=` lazily whenever it's opened for
 * a slug — the composable caches per slug so re-opening is instant.
 */
const props = defineProps({
    /** Controlled open state (v-model:open). */
    open: { type: Boolean, default: false },
    /** Slug of the template to render. */
    slug: { type: String, default: null },
    /** Addon CP API base (e.g. `.../automations/api`). */
    apiBase: { type: String, required: true },
});

defineEmits(['update:open']);

const { preview, loading, error, load } = useEmailTemplatePreview(props.apiBase);

function reload() {
    load(props.slug);
}

// Fetch when the modal opens (or the target slug changes while open). Closing
// keeps the last render cached; the composable serves it instantly next time.
watch(
    () => [props.open, props.slug],
    ([isOpen, slug]) => {
        if (isOpen && slug) load(slug);
    },
    { immediate: true },
);
</script>
