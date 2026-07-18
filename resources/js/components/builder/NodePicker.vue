<template>
    <!-- Zapier-style node chooser. Opens from a "+" trigger (an adder node or an
         edge insert button) and lists the library grouped by kind. Emits the
         chosen handle; the parent decides where in the sequence it lands. -->
    <Dropdown :side="side" align="center">
        <template #trigger>
            <slot />
        </template>

        <div class="sa-picker">
            <template v-for="group in groups" :key="group.key">
                <div v-if="group.items.length" class="sa-picker__label">{{ group.label }}</div>
                <DropdownItem
                    v-for="item in group.items"
                    :key="item.handle"
                    :icon="nodeIcon(item.handle, group.kind)"
                    :text="item.label"
                    @click="$emit('select', item.handle)"
                />
            </template>
        </div>
    </Dropdown>
</template>

<script setup>
import { computed } from 'vue';
import { Dropdown, DropdownItem } from '@statamic/cms/ui';
import { nodeIcon } from '../../composables/useNodeIcon.js';

const props = defineProps({
    library: { type: Object, required: true },
    // 'trigger' → only triggers (the first/root step); 'step' → logic + actions.
    mode: { type: String, default: 'step' },
    side: { type: String, default: 'bottom' },
});

defineEmits(['select']);

const groups = computed(() => {
    if (props.mode === 'trigger') {
        return [
            { key: 'triggers', kind: 'trigger', label: __('Triggers'), items: props.library.triggers ?? [] },
        ];
    }
    return [
        { key: 'logic', kind: 'logic', label: __('Logic'), items: props.library.logic ?? [] },
        { key: 'actions', kind: 'action', label: __('Actions'), items: props.library.actions ?? [] },
    ];
});
</script>
