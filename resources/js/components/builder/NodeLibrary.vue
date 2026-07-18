<template>
    <div class="p-3">
        <h3 class="text-2xs uppercase tracking-wider text-gray-500 dark:text-gray-400 m-0 mb-2">
            {{ __('Node library') }}
        </h3>
        <Input
            v-model="search"
            type="search"
            :placeholder="__('Filter nodes…')"
            class="mb-3"
        />

        <section v-for="group in visibleGroups" :key="group.key" class="mb-2">
            <button
                type="button"
                class="sa-section-header mb-1 py-1"
                @click="toggle(group.key)"
            >
                <span class="flex items-center gap-1.5">
                    <Icon
                        :name="open[group.key] ? 'chevron-down' : 'chevron-right'"
                        class="size-3 text-gray-400"
                    />
                    {{ group.label }}
                </span>
                <Badge :text="String(group.items.length)" size="sm" color="default" pill />
            </button>

            <ul v-show="open[group.key]" class="flex flex-col gap-1">
                <li
                    v-for="item in group.items"
                    :key="item.handle"
                    class="group flex items-start gap-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2.5 py-2 cursor-pointer hover:border-blue-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    @click="$emit('add', item.handle)"
                >
                    <span class="sa-icon-chip sa-icon-chip--sm" :class="`sa-icon-chip--${group.kind}`">
                        <Icon :name="nodeIcon(item.handle, group.kind)" class="size-3.5" />
                    </span>
                    <div class="min-w-0">
                        <div class="text-sm font-medium leading-tight truncate">{{ item.label }}</div>
                        <div v-if="item.description" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-snug">
                            {{ item.description }}
                        </div>
                    </div>
                </li>
            </ul>
        </section>

        <p v-if="!hasResults" class="text-xs text-gray-500 dark:text-gray-400 text-center py-6">
            {{ __('No nodes match your search.') }}
        </p>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Badge, Icon, Input } from '@statamic/cms/ui';
import { nodeIcon } from '../../composables/useNodeIcon.js';

const props = defineProps({
    library: { type: Object, required: true },
});

defineEmits(['add']);

const search = ref('');

// Collapsible state per group (all open by default).
const open = reactive({ triggers: true, logic: true, actions: true });

function toggle(key) {
    open[key] = !open[key];
}

const groups = computed(() => [
    { key: 'triggers', kind: 'trigger', label: __('Triggers'), items: props.library.triggers ?? [] },
    { key: 'logic', kind: 'logic', label: __('Logic'), items: props.library.logic ?? [] },
    { key: 'actions', kind: 'action', label: __('Actions'), items: props.library.actions ?? [] },
]);

function filterItems(items) {
    if (!search.value) return items;
    const needle = search.value.toLowerCase();
    return items.filter(
        (item) =>
            item.label.toLowerCase().includes(needle) ||
            item.handle.toLowerCase().includes(needle),
    );
}

const visibleGroups = computed(() =>
    groups.value
        .map((g) => ({ ...g, items: filterItems(g.items) }))
        .filter((g) => g.items.length > 0),
);

const hasResults = computed(() => visibleGroups.value.length > 0);
</script>
