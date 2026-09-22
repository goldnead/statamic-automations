<template>
    <div class="flex flex-col gap-2">
        <!-- Leiste: was gezeigt wird, und wie breit. Der erste Schalter
             erscheint nur, wenn dieser Schritt schon einmal verschickt hat —
             sonst gibt es nichts zu vergleichen. -->
        <div class="flex flex-wrap items-center gap-2">
            <ToggleGroup v-if="snapshot" v-model="tab" size="sm">
                <ToggleItem value="draft" icon="eye" :label="__('Draft')" />
                <ToggleItem value="sent" icon="mail" :label="sentLabel" />
            </ToggleGroup>

            <!-- Gerätewahl wie im Vorschau-Panel von statamic-funnels: eine
                 Auswahl, und `frameStyle` setzt die Breite des Rahmens. Die
                 Liste ist hier zwei Einträge lang statt der Geräte aus
                 `config/live_preview.php` — eine Mail hat keine Seitenbreite,
                 sie hat einen Posteingang am Schreibtisch und einen am Handy. -->
            <Select
                v-model="device"
                :options="deviceOptions"
                size="sm"
                class="ms-auto w-32 shrink-0"
                :aria-label="__('Preview width')"
            />
        </div>

        <div
            v-if="!automationId"
            class="flex min-h-40 items-center justify-center rounded-xl border border-dashed border-gray-200 px-4 text-center text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400"
        >
            {{ __('The preview appears once this automation has been saved.') }}
        </div>

        <template v-else>
            <!-- Der Betreff gehört zur Mail, steht aber außerhalb des Rahmens:
                 im Rahmen liegt fremdes HTML, und unsere eigene Zeile hat darin
                 nichts verloren. -->
            <header
                v-if="tab === 'draft' && mail"
                class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
            >
                <p class="m-0 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                    {{ __('Subject') }}
                </p>
                <h4
                    data-sa-preview-subject
                    class="m-0 text-[0.9rem] font-semibold leading-snug text-gray-900 dark:text-gray-100"
                >
                    {{ mail.subject || __('(no subject)') }}
                </h4>
            </header>

            <div class="relative flex justify-center overflow-auto rounded-xl border border-gray-200 bg-gray-100 p-2 dark:border-gray-800 dark:bg-gray-900">
                <!-- Ein Streifen, kein Vollbild-Spinner: beim Tippen wird alle
                     400 ms neu geladen, und ein Rahmen, der dabei jedes Mal
                     verschwindet, ist schlimmer als ein kurz veralteter. -->
                <div
                    v-if="loading"
                    class="absolute end-2 top-2 z-10 rounded-full bg-content-bg px-2 py-0.5 text-[10px] text-gray-500 shadow-sm"
                >
                    {{ __('Loading…') }}
                </div>

                <!-- Der Grund kommt vom Endpunkt. Eine feste Zeile hier war Bug F17. -->
                <div
                    v-if="error"
                    class="flex min-h-40 flex-col items-center justify-center gap-2 px-3 text-center text-xs text-red-600 dark:text-red-400"
                >
                    <Icon name="warning-diamond" class="size-5 shrink-0" />
                    <span class="font-medium">{{ __('This mail cannot be displayed.') }}</span>
                    <span class="text-gray-600 dark:text-gray-400">{{ error }}</span>
                    <Button size="xs" variant="filled" :text="__('Try again')" @click="refresh(true)" />
                </div>

                <!-- Der Schnappschuss bringt seine eigene fertige Seite mit
                     (Kopf und Hinweisleiste inklusive), deshalb `src` statt
                     `srcdoc`. -->
                <iframe
                    v-else-if="tab === 'sent' && snapshot"
                    :key="snapshot.url"
                    :src="snapshot.url"
                    sandbox="allow-same-origin"
                    :style="frameStyle"
                    class="sa-email-canvas block h-[22rem] max-w-full rounded border-0 bg-white"
                    :title="__('How this mail went out')"
                />

                <!-- `sandbox` ohne ein einziges Token. Im Rahmen steht HTML, das
                     ein CP-Benutzer geschrieben hat, und die Seite drumherum ist
                     das Control Panel. `allow-scripts` schaltet die Ausführung
                     wieder an, `allow-same-origin` gibt dem Rahmen die Herkunft
                     des CP zurück — zusammen sind die beiden laut Spezifikation
                     dasselbe wie gar kein Sandkasten, der Rahmen kann sich das
                     Attribut dann selbst abnehmen. Bis 2.18.1 stand hier
                     `allow-same-origin`, obwohl der Inhalt aus `srcdoc` kommt
                     und keine Herkunft braucht. Die Gegenprobe steht in
                     tests/js/preview-sandbox.test.js.

                     Kein `:key` auf dem Rahmen: der Inhalt wechselt beim Tippen
                     alle 400 ms, und ein neu gebautes Element blitzt jedes Mal
                     weiß auf und springt nach oben. -->
                <iframe
                    v-else-if="mail"
                    ref="frame"
                    :srcdoc="mail.html"
                    sandbox=""
                    :style="frameStyle"
                    class="sa-email-canvas block h-[22rem] max-w-full rounded border-0 bg-white"
                    :title="__('Email preview')"
                />

                <div
                    v-else
                    class="flex min-h-40 items-center justify-center text-xs text-gray-500 dark:text-gray-400"
                >
                    <span>{{ loading ? __('Loading…') : __('Nothing to preview yet.') }}</span>
                </div>
            </div>

            <p v-if="tab === 'draft' && mail" class="m-0 text-center text-[11px] text-gray-400 dark:text-gray-500">
                {{ mail.source === 'template'
                    ? __('The template this step sends, with sample data (Max Mustermann) — unsaved changes included.')
                    : __('This step\'s own text, with sample data (Max Mustermann) — unsaved changes included.') }}
            </p>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { Button, Icon, Select, ToggleGroup, ToggleItem } from '@statamic/cms/ui';

/**
 * Die Mail dieses Schritts, im Formular selbst.
 *
 * Zwei Dinge unterscheiden diese Fläche vom Modal, das hier bis 2.18.1 stand
 * (`MailPreviewModal.vue`, weiterhin in Gebrauch für die Mails-Liste):
 *
 *  1. **Sie liegt im Node-Stack**, nicht in einer zweiten Ebene darüber. Wer
 *     einen Mail-Knoten öffnet, sieht die Mail, ohne noch einmal zu klicken.
 *  2. **Sie zeigt den Formularzustand, nicht die Datenbank.** Der Betreff, den
 *     jemand gerade tippt, ist der Betreff in der Vorschau — 400 ms später.
 *     Das Vorbild ist das Vorschau-Panel in `statamic-funnels`, das den Graphen
 *     vom Bildschirm an den Server gibt statt den gespeicherten zu rendern.
 *
 * Deshalb POST mit dem Rumpf `{ config }` statt GET: derselbe Endpunkt, dieselbe
 * Berechtigung, aber die Konfiguration kommt aus dem Formular. Ohne Rumpf
 * rendert er weiter den gespeicherten Knoten — die Mails-Liste ruft ihn so auf.
 */
const props = defineProps({
    /** Addon CP API base (z. B. `.../automations/api`). */
    apiBase: { type: String, required: true },
    /** Die Id des Ablaufs. Ohne sie gibt es nichts zu rendern (neuer, ungespeicherter Ablauf). */
    automationId: { type: [String, Number], default: null },
    /** Der Schritt, dessen Mail gezeigt wird. */
    nodeKey: { type: String, default: null },
    /** Der **ungespeicherte** Formularzustand dieses Schritts. */
    config: { type: Object, default: () => ({}) },
});

const mail = ref(null);
const snapshot = ref(null);
const loading = ref(false);
const error = ref(null);
const tab = ref('draft');
const device = ref('desktop');

const deviceOptions = computed(() => [
    { value: 'desktop', label: __('Desktop') },
    { value: 'mobile', label: __('Mobile') },
]);

// Wie in funnels' PreviewPanel: die Wahl setzt nur die Breite des Rahmens.
const frameStyle = computed(() =>
    device.value === 'mobile' ? { width: '390px' } : { width: '100%' },
);

const sentLabel = computed(() =>
    snapshot.value?.send_count > 1
        ? __('Sent (:n times)', { n: snapshot.value.send_count })
        : __('Sent'),
);

/**
 * Die drei Schlüssel, aus denen eine Mail entsteht — und nur die.
 *
 * Der Wächter darunter ist ein Vergleich auf genau dieses Stück: ein Klick auf
 * eine Bedingung, ein geänderter Empfänger oder ein umbenannter Knoten ändert
 * die Mail nicht und soll keine Anfrage auslösen.
 */
const payload = computed(() => ({
    template: props.config?.template ?? '',
    subject: props.config?.subject ?? '',
    body: props.config?.body ?? '',
}));

let timer = null;
let inflight = null;

/**
 * Neu rendern lassen.
 *
 * Entprellt (400 ms wie in funnels) und die vorige Anfrage wird abgebrochen
 * statt mit der neuen um das Ergebnis zu rennen: beim Tippen fällt pro
 * Tastendruck eine an, und die zuletzt eintreffende Antwort ist nicht
 * zwangsläufig die Antwort auf die letzte Frage.
 */
function refresh(immediate = false) {
    clearTimeout(timer);
    timer = setTimeout(
        async () => {
            if (!props.nodeKey || !props.apiBase || !props.automationId) {
                mail.value = null;
                snapshot.value = null;

                return;
            }

            inflight?.abort();
            const mine = (inflight = new AbortController());
            loading.value = true;
            error.value = null;

            try {
                const { data } = await axios.post(
                    `${props.apiBase}/automations/${props.automationId}/mails/${encodeURIComponent(props.nodeKey)}/preview`,
                    { config: payload.value },
                    { signal: mine.signal },
                );

                // Der Abbruch oben ist der erste Riegel, dieser hier der
                // zweite, und er ist nicht überflüssig: ob eine abgebrochene
                // Anfrage wirklich abbricht, entscheidet die Bibliothek, und
                // eine Antwort, die trotzdem ankommt, schriebe über die frische
                // hinweg — die Vorschau zeigte dann, was zwei Tastendrücke
                // vorher im Formular stand. Nur die aktuelle Anfrage schreibt.
                if (inflight !== mine) return;

                const result = data?.data ?? data;
                // `source: 'empty'` ist der Anfangszustand jedes frisch
                // eingefügten Mail-Knotens: keine Vorlage, kein Betreff, kein
                // Text. Der gehört in den neutralen Leerzustand unten und nicht
                // in den roten Kasten — deshalb kein `mail`, kein Fehler.
                mail.value = result?.source === 'empty' ? null : result;
                snapshot.value = result?.snapshot ?? null;
                if (!snapshot.value) tab.value = 'draft';
            } catch (err) {
                if (axios.isCancel?.(err) || err?.name === 'CanceledError' || err?.name === 'AbortError') return;

                // Und ein überholter Fehlschlag darf ein frisches Ergebnis
                // genauso wenig überschreiben wie eine überholte Antwort.
                if (inflight !== mine) return;

                // Der Endpunkt sagt, was los ist. Nur wenn er gar nicht
                // antwortet, bleibt uns ein eigener Satz.
                error.value =
                    err?.response?.data?.message ||
                    err?.message ||
                    __('The preview could not be loaded. The reason is in the Laravel log.');
                mail.value = null;
                snapshot.value = null;
            } finally {
                // Nur die Anfrage, die noch die aktuelle ist, darf den Hinweis
                // wegnehmen. Eine abgebrochene, die später fertig wird, würde
                // ihn löschen, während ihre Nachfolgerin noch unterwegs ist.
                if (inflight === mine) loading.value = false;
            }
        },
        immediate ? 0 : 400,
    );
}

// Ein anderer Schritt ist eine andere Mail: sofort, nicht entprellt.
watch(
    () => [props.nodeKey, props.automationId],
    () => {
        tab.value = 'draft';
        refresh(true);
    },
);

// Jede Änderung an Vorlage, Betreff oder Text ist eine Änderung an der Mail.
watch(() => JSON.stringify(payload.value), () => refresh());

refresh(true);

onBeforeUnmount(() => {
    clearTimeout(timer);
    inflight?.abort();
});
</script>
