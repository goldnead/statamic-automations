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
 * ## Coalescing (`record(tag)`)
 *
 * A snapshot per keystroke makes the stack useless: a node label is a text
 * field, so a hundred typed characters evict every structural step from a
 * hundred-entry stack, and the delete the user wants back is no longer in it.
 * `record()` therefore takes an optional `tag`. Consecutive tagged records
 * carrying the SAME tag within `coalesceMs` fold into the entry the run
 * started with, so one burst of typing on one field costs one undo step.
 *
 * The cut is deliberate:
 *
 * - **Structural steps are never coalesced.** `record()` with no tag (add,
 *   delete, duplicate, connect, replace, enable/disable) always gets its own
 *   entry, whatever happened before or after it — those are the steps a user
 *   means when reaching for undo, and none of them can be repeated fast
 *   enough to belong to the same gesture anyway.
 * - **Text is coalesced per field, per burst.** The tag identifies what is
 *   being edited (`label:<node_key>`, `config:<node_key>`), so moving to
 *   another field or another node ends the run even mid-typing; a pause
 *   longer than `coalesceMs` ends it too, which is where a user's own sense
 *   of "one edit" ends.
 * - **Undo/redo/reset end the run**, so typing after an undo cannot fold into
 *   the entry that undo just restored past.
 *
 * @param {Object}   options
 * @param {Function} options.getState  () => ({ nodes, edges }) — current graph.
 * @param {Function} options.setState  (state) => void — apply a restored graph.
 * @param {number}   [options.max=100] Max undo depth (oldest entries dropped).
 * @param {number}   [options.coalesceMs=600] Window in which two records with
 *                   the same tag count as one edit.
 * @param {Function} [options.now] Clock, injectable so the window is testable
 *                   without waiting for it.
 */
import { computed, ref } from 'vue';

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

export function useHistory({ getState, setState, max = 100, coalesceMs = 600, now = () => Date.now() }) {
    const undoStack = ref([]);
    const redoStack = ref([]);

    // The last committed graph. Seeded from the initial state so the first
    // `record()` can restore back to where the user started.
    let present = clone(getState());

    // The open coalescing run: the tag of the last recorded edit and when it
    // landed. `null` means the next record starts a new entry no matter what.
    let lastTag = null;
    let lastAt = 0;

    function endRun() {
        lastTag = null;
        lastAt = 0;
    }

    /**
     * @param {string|null} [tag] Coalescing identity of this edit — same tag
     *   within `coalesceMs` folds into the previous entry. Omit for structural
     *   changes, which always get their own entry.
     */
    function record(tag = null) {
        const next = clone(getState());
        // Ignore no-op mutations (e.g. a drag that ended where it began).
        if (JSON.stringify(next) === JSON.stringify(present)) {
            return;
        }

        const at = now();
        // The first edit of a run still pushes: it is the entry every later
        // keystroke of that run folds into, and it is what undo returns to.
        const coalesce = tag !== null && tag === lastTag && (at - lastAt) <= coalesceMs;

        if (!coalesce) {
            undoStack.value.push(present);
            if (undoStack.value.length > max) {
                undoStack.value.shift();
            }
        }

        present = next;
        lastTag = tag;
        lastAt = at;

        if (redoStack.value.length) {
            redoStack.value = [];
        }
    }

    function undo() {
        if (!undoStack.value.length) {
            return;
        }
        endRun();
        redoStack.value.push(present);
        present = undoStack.value.pop();
        setState(clone(present));
    }

    function redo() {
        if (!redoStack.value.length) {
            return;
        }
        endRun();
        undoStack.value.push(present);
        present = redoStack.value.pop();
        setState(clone(present));
    }

    /** Re-baseline after a fresh load / save so old edits aren't undoable. */
    function reset() {
        undoStack.value = [];
        redoStack.value = [];
        present = clone(getState());
        endRun();
    }

    const canUndo = computed(() => undoStack.value.length > 0);
    const canRedo = computed(() => redoStack.value.length > 0);

    return { record, undo, redo, reset, canUndo, canRedo };
}
