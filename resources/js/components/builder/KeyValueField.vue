<template>
    <div class="space-y-1.5">
        <div
            v-for="(row, i) in rows"
            :key="row.id"
            class="grid grid-cols-[1fr_1fr_auto] gap-1.5 items-center"
        >
            <Input
                :model-value="row.key"
                :placeholder="keyLabel"
                :input-class="duplicateKeys.has(i) && 'ring-2 ring-red-500/70 dark:ring-red-500/70'"
                @update:model-value="updateRow(i, 'key', $event)"
            />
            <div class="flex items-center gap-1">
                <Input
                    :id="valueDomId(i)"
                    :model-value="row.value"
                    :placeholder="valueLabel"
                    class="flex-1 min-w-0"
                    @update:model-value="updateRow(i, 'value', $event)"
                />
                <!-- Value side accepts tokens — insert an upstream variable at
                     the caret of this row's value input. Omitted when no
                     variables are available (the parent passes none). -->
                <TokenInserter
                    v-if="variables.length"
                    :variables="variables"
                    :model-value="row.value"
                    :target-id="valueDomId(i)"
                    @update:model-value="updateRow(i, 'value', $event)"
                />
            </div>
            <Button
                variant="ghost"
                size="sm"
                icon-only
                icon="trash"
                :aria-label="__('Remove row')"
                @click="removeRow(i)"
            />
        </div>

        <Button
            variant="ghost"
            size="sm"
            icon="plus"
            :text="__('Add row')"
            @click="addRow"
        />

        <p v-if="duplicateKeys.size" class="text-xs text-red-600 dark:text-red-400">
            {{ __('Doppelte Schlüssel werden zusammengeführt, nur der letzte zählt.') }}
        </p>
    </div>
</template>

<script setup>
/**
 * Row editor for `key_value` config fields (Switch `cases`, Parallel
 * `branches`, and every action's `data`/`variables`/`headers` field).
 *
 * `cases`/`branches` are the shapes `outputsFor()` in
 * `composables/useAutoLayout.js` reads to derive a node's dynamic output
 * handles (one per key), so this component always EMITS AN OBJECT MAP
 * (`{ key: value }`) — the same shape the backend's `NormalizesKeyValue`
 * trait treats as already-normalised. Never emit an array or string; that
 * would silently zero out the dynamic outputs on the canvas.
 *
 * Internally it keeps its own `rows` (array of `{ id, key, value }`, `id`
 * only for stable `v-for` keys) rather than deriving rows straight from
 * `modelValue` on every render. Two rows can transiently share a key while
 * the user is mid-edit (e.g. typing over a key to rename it) or a freshly
 * added row can have an empty key before it's filled in — both would
 * collapse or vanish if rows were recomputed from the emitted object every
 * time. `lastEmitted` remembers the object this component itself emitted
 * most recently; when the parent hands the same value straight back down
 * (which happens synchronously on every keystroke, since ConfigPanel just
 * spreads the new config back onto `node.config`), the incoming prop is
 * recognised as an echo and the local rows are left alone. Only a value
 * that actually differs from our own last emission (undo/redo, switching
 * to a different node, initial load) triggers a full resync from props.
 */
import { ref, computed, watch } from 'vue';
import { Input, Button } from '@statamic/cms/ui';
import TokenInserter from './TokenInserter.vue';
import { makeRow, toRows, rowsToObject, duplicateKeyIndices } from '../../composables/useKeyValueRows.js';

const props = defineProps({
    modelValue: { type: [Object, Array, String, null], default: () => ({}) },
    keyLabel: { type: String, default: null },
    valueLabel: { type: String, default: null },
    // Upstream token variables (`{ token, label, source, sample }[]`) offered on
    // each value cell. Empty ⇒ no per-row picker is rendered. Supplied by
    // ConfigPanel from `useNodeVariables`.
    variables: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const keyLabel = props.keyLabel ?? __('Key');
const valueLabel = props.valueLabel ?? __('Value');

// Unique-per-instance base for value-input DOM ids, so TokenInserter can find
// the right native <input> to splice a token into — and two key_value fields
// on the same node never collide on `getElementById`.
const instanceId = Math.random().toString(36).slice(2, 9);
function valueDomId(i) {
    return `sa-kv-${instanceId}-value-${i}`;
}

const rows = ref(toRows(props.modelValue));

// Rows whose key duplicates an earlier row's key (empty keys don't count).
// `rowsToObject` is last-write-wins, so the duplicate silently overwrites an
// earlier mapping/output-edge unless this is surfaced in the UI.
const duplicateKeys = computed(() => new Set(duplicateKeyIndices(rows.value)));
let lastEmitted = JSON.stringify(rowsToObject(rows.value));

watch(
    () => props.modelValue,
    (value) => {
        const incoming = JSON.stringify(rowsToObject(toRows(value)));
        // Echo of our own last emit (the normal case on every keystroke) —
        // leave the in-progress rows (blank/duplicate keys, ordering) alone.
        if (incoming === lastEmitted) return;
        rows.value = toRows(value);
        lastEmitted = JSON.stringify(rowsToObject(rows.value));
    },
);

function commit() {
    const obj = rowsToObject(rows.value);
    lastEmitted = JSON.stringify(obj);
    emit('update:modelValue', obj);
}

function updateRow(index, field, value) {
    rows.value = rows.value.map((row, i) => (i === index ? { ...row, [field]: value } : row));
    commit();
}

function addRow() {
    rows.value = [...rows.value, makeRow('', '')];
    // No commit here: an empty-key row contributes nothing to the emitted
    // object, so there is nothing to save yet — it becomes real once a key
    // is typed (see updateRow → commit).
}

function removeRow(index) {
    rows.value = rows.value.filter((_, i) => i !== index);
    commit();
}
</script>
