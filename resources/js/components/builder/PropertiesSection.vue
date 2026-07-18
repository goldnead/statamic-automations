<template>
    <section class="border-b border-gray-200 dark:border-gray-800">
        <component
            :is="collapsible ? 'button' : 'div'"
            :type="collapsible ? 'button' : undefined"
            class="sa-section-header px-4 py-2.5"
            :class="collapsible && 'cursor-pointer'"
            @click="collapsible && (isOpen = !isOpen)"
        >
            <span>{{ title }}</span>
            <Icon
                v-if="collapsible"
                :name="isOpen ? 'chevron-down' : 'chevron-right'"
                class="size-3 text-gray-400"
            />
        </component>

        <div v-show="isOpen" class="px-4 pb-4">
            <slot />
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { Icon } from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    collapsible: { type: Boolean, default: true },
    defaultOpen: { type: Boolean, default: true },
});

const isOpen = ref(props.defaultOpen);
</script>
