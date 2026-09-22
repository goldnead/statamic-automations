<template>
    <Modal
        :open="open"
        :title="__('E-Mail-Vorschau')"
        icon="eye"
        class="max-w-3xl!"
        @update:open="$emit('update:open', $event)"
    >
        <div class="flex flex-col gap-3">
            <!-- Der Schrittwähler. Nur wenn es etwas zu wählen gibt: am
                 einzelnen Knoten steht die Mail schon fest, und eine Leiste mit
                 einem Knopf ist Zierrat. -->
            <div v-if="mails.length > 1" class="flex items-center gap-2">
                <Button
                    size="xs"
                    variant="ghost"
                    icon="arrow-left"
                    :aria-label="__('Vorherige Mail')"
                    :disabled="index <= 0"
                    @click="step(-1)"
                />
                <nav class="flex min-w-0 flex-1 gap-1 overflow-x-auto" :aria-label="__('Mails dieses Ablaufs')">
                    <button
                        v-for="(mail, i) in mails"
                        :key="mail.node_key"
                        type="button"
                        :aria-current="i === index ? 'step' : undefined"
                        class="shrink-0 rounded-full border px-2.5 py-1 text-xs transition-colors"
                        :class="i === index
                            ? 'border-primary bg-primary/10 text-gray-900 dark:bg-primary/20 dark:text-gray-100'
                            : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-gray-800/60'"
                        @click="current = mail.node_key"
                    >
                        {{ i + 1 }}. {{ mail.label }}
                    </button>
                </nav>
                <Button
                    size="xs"
                    variant="ghost"
                    icon="arrow-right"
                    :aria-label="__('Nächste Mail')"
                    :disabled="index >= mails.length - 1"
                    @click="step(1)"
                />
            </div>

            <!-- Vorlage von heute oder das, was rausging. Der zweite Reiter
                 erscheint nur, wenn es zu dieser Mail einen Versand gibt. -->
            <ToggleGroup v-if="snapshot" v-model="tab" size="sm">
                <ToggleItem value="template" icon="eye" :label="__('Vorlage von heute')" />
                <ToggleItem value="sent" icon="mail" :label="sentLabel" />
            </ToggleGroup>

            <div v-if="loading" class="flex flex-col items-center justify-center gap-2 min-h-72 text-sm text-gray-500 dark:text-gray-400">
                <Icon name="loading" class="size-5 animate-spin" />
                <span>{{ __('Vorschau wird geladen…') }}</span>
            </div>

            <!-- Der Grund kommt vom Endpunkt. Eine feste Zeile hier war Bug F17. -->
            <div
                v-else-if="error"
                class="flex flex-col items-center justify-center gap-2 min-h-72 px-6 text-center text-sm text-red-600 dark:text-red-400"
            >
                <Icon name="warning-diamond" class="size-5 shrink-0" />
                <span class="font-medium">{{ __('Diese Mail lässt sich nicht anzeigen.') }}</span>
                <span class="text-xs text-gray-600 dark:text-gray-400">{{ error }}</span>
                <Button size="xs" variant="filled" :text="__('Erneut versuchen')" @click="load(true)" />
            </div>

            <template v-else-if="mail">
                <header
                    v-if="tab === 'template'"
                    class="px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900"
                >
                    <p class="m-0 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                        {{ __('Betreff') }}
                    </p>
                    <h4 class="m-0 text-[0.95rem] font-semibold leading-snug text-gray-900 dark:text-gray-100">
                        {{ mail.subject || __('(kein Betreff)') }}
                    </h4>
                </header>

                <div class="sa-email-canvas mx-auto w-full max-w-[640px] overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
                    <!-- Der Schnappschuss bringt seine eigene fertige Seite mit
                         (Kopf und Hinweisleiste inklusive), deshalb `src` statt
                         `srcdoc` und deshalb steht darunter kein zweiter
                         Hinweis von uns. -->
                    <iframe
                        v-if="tab === 'sent'"
                        :key="snapshot.url"
                        :src="snapshot.url"
                        sandbox="allow-same-origin"
                        class="sa-email-canvas block w-full h-[42vh] min-h-72 border-0"
                        :title="__('Wie diese Mail rausging')"
                    />
                    <!-- `sandbox` ohne Token: der Inhalt kommt aus `srcdoc`
                         und braucht keine Herkunft. `allow-same-origin` gäbe
                         dem Rahmen die des Control Panels zurück, und im Rahmen
                         steht HTML, das ein CP-Benutzer geschrieben hat.
                         Gegenprobe: tests/js/preview-sandbox.test.js. -->
                    <iframe
                        v-else
                        :srcdoc="mail.html"
                        sandbox=""
                        class="sa-email-canvas block w-full h-[42vh] min-h-72 border-0"
                        :title="__('E-Mail-Vorschau')"
                        loading="lazy"
                    />
                </div>

                <p v-if="tab === 'template'" class="m-0 text-center text-xs text-gray-400 dark:text-gray-500">
                    {{ mail.source === 'template'
                        ? __('Die Vorlage, die dieser Schritt heute verschickt, mit Beispieldaten (Max Mustermann).')
                        : __('Der eigene Text dieses Schritts, mit Beispieldaten (Max Mustermann).') }}
                </p>
            </template>

            <div v-else class="flex items-center justify-center min-h-72 text-sm text-gray-500 dark:text-gray-400">
                <span>{{ __('Keine Mail ausgewählt.') }}</span>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Modal, Button, Icon, ToggleGroup, ToggleItem } from '@statamic/cms/ui';

/**
 * Die Mail eines Ablaufs, angesehen — an einem Schritt oder durch alle hindurch.
 *
 * Zwei Einbauorte, ein Bauteil: die Mails-Ansicht übergibt alle Mails und den
 * angeklickten Schritt, der E-Mail-Knoten übergibt nur sich selbst. Was sich
 * unterscheidet, ist die Länge von `mails` — der Schrittwähler oben zeigt sich
 * erst ab zwei.
 *
 * Zwei Reiter, wenn es beide gibt: **die Vorlage von heute** (gerendert mit
 * Beispieldaten, aus unserem eigenen Endpunkt) und **was rausging** (die
 * CP-Seite der Schnappschuss-Schicht in email-templates, direkt als `src`).
 * Der zweite Reiter fehlt, solange dieser Schritt nichts verschickt hat.
 */
const props = defineProps({
    /** Controlled open state (v-model:open). */
    open: { type: Boolean, default: false },
    /** Addon CP API base (z. B. `.../automations/api`). */
    apiBase: { type: String, required: true },
    /** Die Id des Ablaufs, dem die Mails gehören. */
    automationId: { type: [String, Number], default: null },
    /** `[{ node_key, label }]` — die Mails, durch die geblättert werden kann. */
    mails: { type: Array, default: () => [] },
    /** Welche davon gezeigt wird. */
    nodeKey: { type: String, default: null },
});

defineEmits(['update:open']);

const current = ref(props.nodeKey);
const mail = ref(null);
const snapshot = ref(null);
const loading = ref(false);
const error = ref(null);
const tab = ref('template');
let seq = 0;

const index = computed(() => props.mails.findIndex((m) => m.node_key === current.value));

const sentLabel = computed(() =>
    snapshot.value?.send_count > 1
        ? __('Wie es rausging (:n Versände)', { n: snapshot.value.send_count })
        : __('Wie es rausging'),
);

function step(delta) {
    const next = props.mails[index.value + delta];
    if (next) current.value = next.node_key;
}

async function load(force = false) {
    const key = current.value;

    if (!key || !props.apiBase || !props.automationId) {
        mail.value = null;
        snapshot.value = null;
        return;
    }

    const my = ++seq;
    loading.value = true;
    error.value = null;

    try {
        const { data } = await axios.get(
            `${props.apiBase}/automations/${props.automationId}/mails/${encodeURIComponent(key)}/preview`,
            force ? { params: { t: Date.now() } } : undefined,
        );
        if (my !== seq) return; // von einer neueren Anfrage überholt
        const result = data?.data ?? data;
        mail.value = result;
        snapshot.value = result?.snapshot ?? null;
        if (!snapshot.value) tab.value = 'template';
    } catch (err) {
        if (my !== seq) return;
        // Der Endpunkt sagt, was los ist. Nur wenn er gar nicht antwortet,
        // bleibt uns ein eigener Satz.
        error.value =
            err?.response?.data?.message ||
            err?.message ||
            __('Die Vorschau konnte nicht geladen werden. Der Grund steht im Laravel-Log.');
        mail.value = null;
        snapshot.value = null;
    } finally {
        if (my === seq) loading.value = false;
    }
}

watch(() => props.nodeKey, (key) => { current.value = key; });

watch([current, () => props.open], ([, isOpen]) => {
    if (isOpen) load();
}, { immediate: true });
</script>
