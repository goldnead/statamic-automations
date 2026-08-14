<template>
    <Dropdown v-if="hasVariables" align="end" side="bottom">
        <template #trigger>
            <Button
                variant="subtle"
                size="xs"
                text="{{ }}"
                class="sa-token-btn font-mono"
                :aria-label="__('Insert variable')"
                :title="__('Insert variable')"
            />
        </template>

        <!-- `DropdownMenu`, not a bare div: the items are `grid-cols-subgrid`
             and the menu is the grid whose columns they subscribe to. A plain
             wrapper leaves them a few pixels wider than the menu they sit in,
             which shows up as a scrollbar along the bottom of the list. -->
        <DropdownMenu class="sa-token-inserter max-h-72">
            <template v-for="group in groupedVariables" :key="group.source">
                <DropdownLabel :text="group.source" />
                <DropdownItem
                    v-for="variable in group.items"
                    :key="variable.token"
                    @click="insert(variable.token)"
                >
                    <span class="flex items-baseline gap-2">
                        <span class="truncate">{{ variable.label }}</span>
                        <span
                            v-if="variable.sample"
                            class="ml-auto shrink-0 max-w-40 truncate text-xs text-gray-400 dark:text-gray-500"
                            :title="variable.sample"
                        >→ &ldquo;{{ variable.sample }}&rdquo;</span>
                    </span>
                </DropdownItem>
            </template>
        </DropdownMenu>
    </Dropdown>

    <!-- No upstream variables yet — disabled placeholder, never a crash. -->
    <Button
        v-else
        variant="subtle"
        size="xs"
        text="{{ }}"
        class="sa-token-btn font-mono"
        disabled
        :aria-label="__('No upstream variables available')"
        :title="__('No upstream variables available')"
    />
</template>

<script setup>
import { computed, nextTick } from 'vue';
import { Button, Dropdown, DropdownMenu, DropdownItem, DropdownLabel } from '@statamic/cms/ui';

/**
 * Small "{{ }}" button/dropdown that lists the upstream variables a node's
 * `tokenable` field can reference (from `useNodeVariables`) and inserts the
 * chosen token string at the caret position of the bound input/textarea.
 *
 * The target field is identified by DOM `id` (`targetId`) rather than a
 * template ref, because this component is mounted as a SIBLING of the
 * actual `<Input>`/`<Textarea>` (in `ConfigPanel.vue`'s `Field` `#actions`
 * slot), not its parent — `document.getElementById` is the simplest way to
 * reach the underlying native element both `@statamic/cms/ui` field
 * components render their `id` prop onto, without depending on their
 * internal ref-forwarding. If the element can't be found (or doesn't
 * support text selection), the token is appended to the end of the value
 * instead — insertion always degrades gracefully, never throws, never
 * corrupts existing content.
 */
const props = defineProps({
    /** `{ token, label, source, sample }[]` from `useNodeVariables`. */
    variables: { type: Array, default: () => [] },
    /** Current field value (coerced to a string before splicing). */
    modelValue: { type: [String, Number, Object, Array, null], default: '' },
    /** `id` of the native input/textarea element to insert into. */
    targetId: { type: String, default: null },
    /**
     * How the chosen token is applied to the field value:
     *  - `insert` (default): splice the token in at the caret of the target
     *    input/textarea — for free-text fields where a token is one part of a
     *    larger string (`"Hallo {{ entry.title }}"`).
     *  - `replace`: set the whole field value to the token — for `select` /
     *    `combobox` fields that accept a token *as* their value (no caret to
     *    splice into; the token IS the value).
     */
    mode: { type: String, default: 'insert' },
});

const emit = defineEmits(['update:modelValue']);

const hasVariables = computed(() => props.variables.length > 0);

// Group by source node so the dropdown reads "Entry Published: entry.id"
// rather than a flat, ambiguous list — while keeping the overall list in
// the nearest-upstream-first order `useNodeVariables` already produced.
const groupedVariables = computed(() => {
    const groups = [];
    const bySource = new Map();
    for (const variable of props.variables) {
        let group = bySource.get(variable.source);
        if (!group) {
            group = { source: variable.source, items: [] };
            bySource.set(variable.source, group);
            groups.push(group);
        }
        group.items.push(variable);
    }
    return groups;
});

function currentValueAsString() {
    const value = props.modelValue;
    if (value === null || value === undefined) return '';
    return typeof value === 'string' ? value : String(value);
}

function insert(token) {
    // `replace` fields (select/combobox) have no caret — the token IS the
    // value, so set it wholesale and skip the splice/caret restore below.
    if (props.mode === 'replace') {
        emit('update:modelValue', token);
        return;
    }

    const el = props.targetId ? document.getElementById(props.targetId) : null;
    const current = currentValueAsString();

    let start = current.length;
    let end = current.length;
    if (el && typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number') {
        start = el.selectionStart;
        end = el.selectionEnd;
    }

    const next = current.slice(0, start) + token + current.slice(end);
    emit('update:modelValue', next);

    // Restore focus + caret just after the inserted token, once the DOM has
    // picked up the new value from the v-model round trip.
    nextTick(() => {
        if (!el || typeof el.focus !== 'function') return;
        el.focus();
        const pos = start + token.length;
        if (typeof el.setSelectionRange === 'function') {
            el.setSelectionRange(pos, pos);
        }
    });
}
</script>
