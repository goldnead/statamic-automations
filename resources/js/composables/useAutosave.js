import { ref, watch, onBeforeUnmount } from 'vue';

/**
 * Debounced autosave for the automation builder.
 *
 * Usage:
 *
 *     const { status, lastSavedAt, enabled, toggle } = useAutosave({
 *         source: () => automation.value,
 *         saver: () => save(),
 *         debounceMs: 2000,
 *     });
 *
 * `source` returns the reactive object whose changes should trigger a save.
 * `saver` is the async function that actually persists the change.
 * `enabled.value` is bound to a checkbox in the topbar; toggling it on
 * starts watching, off cancels any pending timer.
 *
 * Status values:
 *   - "idle"     — nothing scheduled, no recent save
 *   - "pending"  — change detected, save scheduled
 *   - "saving"   — saver running
 *   - "saved"    — last save completed successfully
 *   - "error"    — last save failed
 */
export function useAutosave({ source, saver, debounceMs = 2000, defaultEnabled = false } = {}) {
    const status = ref('idle');
    const lastSavedAt = ref(null);
    const lastError = ref(null);
    const enabled = ref(defaultEnabled);

    let timer = null;
    let stopWatching = null;

    function clearTimer() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    async function flush() {
        clearTimer();
        if (!enabled.value) return;
        status.value = 'saving';
        try {
            await saver();
            status.value = 'saved';
            lastSavedAt.value = new Date();
            lastError.value = null;
        } catch (e) {
            status.value = 'error';
            lastError.value = e?.response?.data?.message || e.message || 'Autosave failed.';
        }
    }

    function schedule() {
        if (!enabled.value) return;
        clearTimer();
        status.value = 'pending';
        timer = setTimeout(flush, debounceMs);
    }

    function start() {
        if (stopWatching) return;
        stopWatching = watch(
            source,
            () => schedule(),
            { deep: true },
        );
    }

    function stop() {
        clearTimer();
        if (stopWatching) {
            stopWatching();
            stopWatching = null;
        }
        status.value = 'idle';
    }

    function toggle(value) {
        const next = typeof value === 'boolean' ? value : !enabled.value;
        enabled.value = next;
        if (next) {
            start();
        } else {
            stop();
        }
    }

    if (defaultEnabled) {
        start();
    }

    onBeforeUnmount(stop);

    return {
        status,
        lastSavedAt,
        lastError,
        enabled,
        flush,
        toggle,
    };
}
