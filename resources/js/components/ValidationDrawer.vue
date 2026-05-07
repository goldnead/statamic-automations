<template>
    <div class="sa-validation">
        <h4 class="sa-validation__title">Validation</h4>
        <ul class="sa-validation__list">
            <li
                v-for="(issue, index) in issues"
                :key="index"
                class="sa-validation__item"
                :class="`sa-validation__item--${issue.level || 'error'}`"
            >
                <span class="sa-validation__level">{{ issue.level || 'error' }}</span>
                <span class="sa-validation__message">{{ issue.message }}</span>
                <span v-if="issue.node_key" class="sa-validation__node">node: {{ issue.node_key }}</span>
            </li>
        </ul>
    </div>
</template>

<script setup>
defineProps({
    issues: { type: Array, default: () => [] },
});
</script>
