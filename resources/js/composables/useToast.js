import { reactive } from 'vue';

/**
 * Tiny global toast bus. Components import the `toast` helper and
 * call e.g. `toast.success('Saved!')`. The Toast.vue component reads
 * the reactive state and renders the latest message.
 */
const state = reactive({
    message: null,
    level: 'info',
    seq: 0,
});

function fire(level, message) {
    state.message = message;
    state.level = level;
    state.seq += 1;
}

export const toast = {
    info: (msg) => fire('info', msg),
    success: (msg) => fire('success', msg),
    error: (msg) => fire('error', msg),
    warning: (msg) => fire('warning', msg),
};

export function useToastState() {
    return state;
}
