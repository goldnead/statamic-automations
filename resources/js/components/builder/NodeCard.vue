<template>
    <div
        class="sa-node"
        :class="[
            `sa-node--${kind}`,
            selected && 'sa-node--selected',
            status === 'error' && 'sa-node--invalid',
            status === 'warning' && 'sa-node--incomplete',
        ]"
    >
        <!-- Header: icon chip · title/subtitle · meta badge · context menu -->
        <div class="sa-node__header">
            <span class="sa-icon-chip">
                <Icon :name="icon" class="size-4" />
            </span>

            <div class="sa-node__heading">
                <div class="sa-node__title">{{ data.label }}</div>
                <div class="sa-node__subtitle">{{ data.type }}</div>
            </div>

            <div class="flex items-center gap-1 shrink-0">
                <Badge :color="kindColor" :text="kindLabel" size="sm" pill />
                <Dropdown side="bottom" align="end">
                    <template #trigger>
                        <Button
                            icon="dots"
                            variant="ghost"
                            size="sm"
                            inset
                            :aria-label="__('Node actions')"
                            @click.stop
                        />
                    </template>
                    <DropdownItem :text="__('Rename')" icon="rename" @click="$emit('rename')" />
                    <DropdownItem :text="__('Duplicate')" icon="duplicate" @click="$emit('duplicate')" />
                    <DropdownItem
                        :text="data.disabled ? __('Enable') : __('Disable')"
                        :icon="data.disabled ? 'eye' : 'eye-slash'"
                        @click="$emit('toggle-disabled')"
                    />
                    <DropdownSeparator />
                    <DropdownItem
                        :text="__('Delete')"
                        icon="trash"
                        variant="destructive"
                        @click="$emit('delete')"
                    />
                </Dropdown>
            </div>
        </div>

        <!-- Body: one config summary line + variable chips (only if present) -->
        <div v-if="summary || chips.length" class="sa-node__body">
            <div v-if="summary" class="sa-node__summary">{{ summary }}</div>
            <div v-if="chips.length" class="sa-node__chips">
                <span v-for="chip in chips" :key="chip" class="sa-chip">{{ chip }}</span>
            </div>
        </div>

        <!-- Footer: status dot + kind label · branch legend -->
        <div class="sa-node__footer">
            <span class="sa-status-dot" :class="statusDotClass" />
            <span class="sa-node__kind-label">{{ statusLabel }}</span>
            <span v-if="hasBranchOutputs" class="ml-auto flex items-center gap-1.5 text-[10px] font-mono">
                <span class="sa-handle-label--true">{{ __('true') }}</span>
                <span class="text-gray-300 dark:text-gray-600">/</span>
                <span class="sa-handle-label--false">{{ __('false') }}</span>
            </span>
        </div>

        <Handle v-if="kind !== 'trigger'" type="target" :position="Position.Left" />
        <template v-if="hasBranchOutputs">
            <Handle id="true" type="source" :position="Position.Right" :style="{ top: '38%' }" />
            <Handle id="false" type="source" :position="Position.Right" :style="{ top: '68%' }" />
        </template>
        <Handle v-else type="source" :position="Position.Right" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Badge, Button, Dropdown, DropdownItem, DropdownSeparator, Icon } from '@statamic/cms/ui';
import { nodeIcon } from '../../composables/useNodeIcon.js';

const props = defineProps({
    kind: { type: String, required: true },
    data: { type: Object, required: true },
    status: { type: String, default: null },
    selected: { type: Boolean, default: false },
});

defineEmits(['rename', 'duplicate', 'toggle-disabled', 'delete']);

const icon = computed(() => nodeIcon(props.data.type, props.kind));

const kindLabel = computed(() => ({
    trigger: __('Trigger'),
    logic: __('Logic'),
    action: __('Action'),
}[props.kind] ?? props.kind));

const kindColor = computed(() => ({
    trigger: 'blue',
    logic: 'amber',
    action: 'emerald',
}[props.kind] ?? 'default'));

const hasBranchOutputs = computed(() => props.data.type === 'branch');

const summary = computed(() => {
    const config = props.data.config ?? {};
    if (props.data.type === 'send_email' && config.to) return `→ ${config.to}`;
    if (props.data.type === 'send_webhook' && config.url) return `POST ${config.url}`;
    if (props.data.type === 'add_log_entry' && config.message) return String(config.message).slice(0, 60);
    if (props.data.type === 'filter' && Array.isArray(config.conditions)) {
        return __(':n condition(s)', { n: config.conditions.length });
    }
    return null;
});

// Antenna-scan config for {{ variable }} references → chips (max 4, deduped).
const chips = computed(() => {
    const found = new Set();
    const scan = (value) => {
        if (typeof value === 'string') {
            const matches = value.match(/\{\{\s*[\w.]+\s*\}\}/g);
            if (matches) matches.forEach((m) => found.add(m.replace(/\s+/g, ' ').trim()));
        } else if (Array.isArray(value)) {
            value.forEach(scan);
        } else if (value && typeof value === 'object') {
            Object.values(value).forEach(scan);
        }
    };
    scan(props.data.config ?? {});
    return [...found].slice(0, 4);
});

const statusDotClass = computed(() => {
    if (props.status === 'error') return 'sa-status-dot--error';
    if (props.status === 'warning') return 'sa-status-dot--warning';
    return 'sa-status-dot--ok';
});

const statusLabel = computed(() => {
    if (props.data.disabled) return __('Disabled');
    if (props.status === 'error') return __('Invalid');
    if (props.status === 'warning') return __('Incomplete');
    return __('Ready');
});
</script>
