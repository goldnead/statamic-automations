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
                @update:model-value="updateRow(i, 'key', $event)"
            />
            <Input
                :model-value="row.value"
                :placeholder="valueLabel"
                @update:model-value="updateRow(i, 'value', $event)"
            />
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
import { ref, watch } from 'vue';
import { Input, Button } from '@statamic/cms/ui';

const props = defineProps({
    modelValue: { type: [Object, Array, String, null], default: () => ({}) },
    keyLabel: { type: String, default: null },
    valueLabel: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const keyLabel = props.keyLabel ?? __('Key');
const valueLabel = props.valueLabel ?? __('Value');

let nextId = 0;
function makeRow(key, value) {
    return { id: nextId++, key: key == null ? '' : String(key), value: value ?? '' };
}

/**
 * Coerce whatever shape the value arrives in into an ordered array of
 * rows. Handles the shapes that can realistically reach this component:
 * a plain object map (the normal, already-normalised case), a list of
 * `{key,value}` or `{handle,label}` pairs or `[key, value]` tuples (data
 * imported/seeded before this editor existed), or a raw JSON string (a
 * value still mid-round-trip from the old Textarea). Anything else
 * degrades to no rows — never throws.
 */
function toRows(raw) {
    if (raw == null) return [];
    if (typeof raw === 'string') {
        if (raw.trim() === '') return [];
        try {
            return toRows(JSON.parse(raw));
        } catch {
            return [];
        }
    }
    if (Array.isArray(raw)) {
        return raw
            .map((item) => {
                if (Array.isArray(item)) return makeRow(item[0], item[1]);
                if (item && typeof item === 'object') {
                    const key = item.key ?? item.handle;
                    const value = item.value ?? item.label;
                    return key != null ? makeRow(key, value) : null;
                }
                return null;
            })
            .filter(Boolean);
    }
    if (typeof raw === 'object') {
        return Object.entries(raw).map(([k, v]) => makeRow(k, v));
    }
    return [];
}

function rowsToObject(list) {
    const out = {};
    for (const row of list) {
        if (row.key === '' || row.key == null) continue;
        out[row.key] = row.value;
    }
    return out;
}

const rows = ref(toRows(props.modelValue));
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
