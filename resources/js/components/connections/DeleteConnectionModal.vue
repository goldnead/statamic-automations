<script setup>
/**
 * Confirms deleting a connection, and says first who still needs it.
 *
 * `used_by` only comes with `show`, so the modal loads the connection when it
 * opens. Deleting is not refused while automations use it — the operator may
 * mean exactly that — but their names stand in the dialog, because a node
 * whose connection is gone fails on every run from then on.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { ConfirmationModal, Alert, Heading } from '@statamic/cms/ui';

const props = defineProps({
    // A connection as the API presents it (`show_url`, `name`), or null.
    connection: { type: Object, default: null },
});

const emit = defineEmits(['deleted', 'failed', 'cancel']);

const usedBy = ref([]);
const loading = ref(false);
const busy = ref(false);

watch(
    () => props.connection,
    async (connection) => {
        usedBy.value = connection?.used_by ?? [];
        if (!connection || connection.used_by) return;

        loading.value = true;
        try {
            const { data } = await axios.get(connection.show_url);
            usedBy.value = data?.data?.used_by ?? [];
        } catch {
            // The list is a warning, not a gate: without it the plain
            // confirmation still stands.
            usedBy.value = [];
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const open = computed(() => props.connection !== null);

async function confirm() {
    busy.value = true;
    try {
        await axios.delete(props.connection.show_url);
        window?.Statamic?.$toast?.success?.(__('Deleted.'));
        emit('deleted');
    } catch (e) {
        emit('failed', e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ConfirmationModal
        :open="open"
        :title="__('Delete connection')"
        :button-text="__('Delete')"
        :busy="busy || loading"
        danger
        @update:open="!$event && emit('cancel')"
        @confirm="confirm"
        @cancel="emit('cancel')"
    >
        <div class="space-y-4" data-connection-delete>
            <p>{{ __('Are you sure you want to delete ":name"?', { name: connection?.name ?? '' }) }}</p>
            <!-- Alert's default slot replaces its heading and text, so both
                 are spelled out here next to the list. -->
            <Alert v-if="usedBy.length" variant="warning" data-connection-used-by>
                <Heading :text="__('Automations still use this connection')" />
                <p class="mt-1 mb-1 text-sm">{{ __('Their steps on this connection will fail on every run once it is deleted:') }}</p>
                <ul class="list-disc list-inside space-y-0.5 text-sm">
                    <li v-for="automation in usedBy" :key="automation.id">{{ automation.name }}</li>
                </ul>
            </Alert>
        </div>
    </ConfirmationModal>
</template>
