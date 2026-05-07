<template>
    <aside class="sa-library">
        <h3 class="sa-library__heading">Add nodes</h3>

        <div v-for="group in groups" :key="group.title" class="sa-library__group">
            <h4 class="sa-library__group-title">{{ group.title }}</h4>
            <ul class="sa-library__list">
                <li v-for="node in group.items" :key="node.handle" class="sa-library__item" @click="$emit('add', node)">
                    <div class="sa-library__item-label">{{ node.label }}</div>
                    <div v-if="node.description" class="sa-library__item-description">{{ node.description }}</div>
                </li>
            </ul>
        </div>

        <div v-if="!hasIntegrations" class="sa-library__hint">
            <strong>Tip:</strong> install <em>LeadHub</em> or <em>Webhook Manager</em> to unlock more triggers and actions.
        </div>
    </aside>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    nodes: { type: Object, required: true },
});

defineEmits(['add']);

const groups = computed(() => {
    const flat = [
        { title: 'Triggers', items: byGroup(props.nodes.triggers ?? []) },
        { title: 'Logic', items: byGroup(props.nodes.logic ?? []) },
        { title: 'Actions', items: byGroup(props.nodes.actions ?? []) },
    ];
    return flat;
});

function byGroup(items) {
    return [...items].sort((a, b) => {
        if (a.group !== b.group) return (a.group ?? '').localeCompare(b.group ?? '');
        return (a.label ?? '').localeCompare(b.label ?? '');
    });
}

const hasIntegrations = computed(() => {
    const integrations = props.nodes.integrations ?? {};
    return integrations.leadhub || integrations.webhook_manager;
});
</script>
