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

        <section v-for="group in groups" :key="group.label" class="mb-3">
            <header class="text-2xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                {{ group.label }}
            </header>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="item in filtered(group.items)"
                    :key="item.handle"
                    class="rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-2.5 py-2 cursor-pointer hover:border-blue-500 transition-colors"
                    @click="$emit('add', item.handle)"
                >
                    <div class="text-sm font-medium">{{ item.label }}</div>
                    <div v-if="item.description" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ item.description }}
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Input } from '@statamic/cms/ui';

const props = defineProps({
    library: { type: Object, required: true },
});

defineEmits(['add']);

const search = ref('');

const groups = computed(() => [
    { label: __('Triggers'), items: props.library.triggers ?? [] },
    { label: __('Logic'), items: props.library.logic ?? [] },
    { label: __('Actions'), items: props.library.actions ?? [] },
]);

function filtered(items) {
    if (!search.value) return items;
    const needle = search.value.toLowerCase();
    return items.filter(
        (item) =>
            item.label.toLowerCase().includes(needle) ||
            item.handle.toLowerCase().includes(needle),
    );
}
</script>
