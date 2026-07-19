/**
 * Pure row <-> value transforms for `KeyValueField.vue`.
 *
 * Pulled out of the component so the stateful logic (coercing whatever shape
 * a `key_value` config field arrives in, collapsing rows back into the
 * object map the backend/canvas expect, and detecting duplicate keys) can be
 * unit-tested without mounting Vue.
 */

let nextId = 0;

/** Build a row object. `id` only exists for stable `v-for` keys. */
export function makeRow(key, value) {
    return { id: nextId++, key: key == null ? '' : String(key), value: value ?? '' };
}

/**
 * Coerce whatever shape the value arrives in into an ordered array of rows.
 * Handles the shapes that can realistically reach this component: a plain
 * object map (the normal, already-normalised case), a list of `{key,value}`
 * or `{handle,label}` pairs or `[key, value]` tuples (data imported/seeded
 * before this editor existed), or a raw JSON string (a value still
 * mid-round-trip from the old Textarea). Anything else degrades to no rows —
 * never throws.
 */
export function toRows(raw) {
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

/**
 * Collapse rows into the `{ key: value }` map the field emits. Empty-key
 * rows contribute nothing. Duplicate keys are last-write-wins — this is
 * intentional (the emitted value must stay a valid object map), but the
 * caller should surface the collision visually via `duplicateKeyIndices()`
 * so it isn't silently lost on the user.
 */
export function rowsToObject(list) {
    const out = {};
    for (const row of list) {
        if (row.key === '' || row.key == null) continue;
        out[row.key] = row.value;
    }
    return out;
}

/**
 * Return the indices of rows whose (non-empty) key duplicates an earlier
 * row's key. The first row to use a given key is never flagged — only the
 * later row(s) that collide with it — matching which row's value actually
 * gets discarded by `rowsToObject`'s last-write-wins merge.
 */
export function duplicateKeyIndices(list) {
    const seen = new Set();
    const duplicates = [];
    for (let i = 0; i < list.length; i++) {
        const key = list[i]?.key;
        if (key === '' || key == null) continue;
        if (seen.has(key)) {
            duplicates.push(i);
        } else {
            seen.add(key);
        }
    }
    return duplicates;
}
