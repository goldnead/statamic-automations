/**
 * Lightweight undo/redo history for the builder graph.
 *
 * Snapshot-based: the caller mutates its own graph state ({ nodes, edges }) and
 * calls `record()` after each discrete operation (add / delete / move / connect
 * / configure). Each record pushes the *previous* snapshot onto the undo stack;
 * `undo()`/`redo()` walk that stack and hand a fresh clone back to the caller
 * via `setState`. Selection and other UI state are intentionally NOT tracked.
 *
 * Kept framework-light (plain refs/computed, deep JSON clones) so it is unit
 * testable in isolation and adds no dependency on Vue Flow internals.
 *
 * @param {Object}   options
 * @param {Function} options.getState  () => ({ nodes, edges }) — current graph.
 * @param {Function} options.setState  (state) => void — apply a restored graph.
 * @param {number}   [options.max=100] Max undo depth (oldest entries dropped).
 */
import { computed, ref } from 'vue';

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

export function useHistory({ getState, setState, max = 100 }) {
    const undoStack = ref([]);
    const redoStack = ref([]);

    // The last committed graph. Seeded from the initial state so the first
    // `record()` can restore back to where the user started.
    let present = clone(getState());

    function record() {
        const next = clone(getState());
        // Ignore no-op mutations (e.g. a drag that ended where it began).
        if (JSON.stringify(next) === JSON.stringify(present)) {
            return;
        }
        undoStack.value.push(present);
        if (undoStack.value.length > max) {
            undoStack.value.shift();
        }
        present = next;
        if (redoStack.value.length) {
            redoStack.value = [];
        }
    }

    function undo() {
        if (!undoStack.value.length) {
            return;
        }
        redoStack.value.push(present);
        present = undoStack.value.pop();
        setState(clone(present));
    }

    function redo() {
        if (!redoStack.value.length) {
            return;
        }
        undoStack.value.push(present);
        present = redoStack.value.pop();
        setState(clone(present));
    }

    /** Re-baseline after a fresh load / save so old edits aren't undoable. */
    function reset() {
        undoStack.value = [];
        redoStack.value = [];
        present = clone(getState());
    }

    const canUndo = computed(() => undoStack.value.length > 0);
    const canRedo = computed(() => redoStack.value.length > 0);

    return { record, undo, redo, reset, canUndo, canRedo };
}
