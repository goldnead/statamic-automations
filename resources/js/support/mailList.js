/**
 * Pure helpers for the mail list view.
 *
 * Everything in here is data in, data out: no `__()`, no components, no CP
 * globals. The wording lives in the Vue components, where a translator can find
 * it; the classification lives here, where it can be tested without a DOM.
 */

/**
 * The seven conditions of `Sequence\LinearityRule`, in the order the rule
 * states them. The UI never says only "not linear" — it says WHICH of the seven
 * is broken, because "not linear" tells an editor nothing they can act on.
 *
 * The rule hands out prose, not codes, so this maps its sentences back onto the
 * conditions they came from. Each pattern is anchored on the one phrase that
 * only that reason uses:
 *
 *   1. "The automation has no trigger node." / "…has 2 trigger nodes; …"
 *   2. "Node 'x' has 2 outgoing edges."
 *   3. "Node 'x' has 2 incoming edges, so more than one path leads to it."
 *   4. "Node 'x' has an edge on the 'true' output, so the flow forks."
 *   5. "Node 'x' is a branch node, which forks the flow by nature."
 *   6. "These nodes cannot be reached from the trigger: a, b."
 *   7. "The chain loops back on itself."
 *
 * Order matters: 6 mentions the trigger too, so it is tested before 1. An
 * unrecognised sentence classifies as `null` and is still shown verbatim —
 * a reason nobody anticipated must never vanish from the screen.
 */
const CLASSIFIERS = [
    [/forks the flow by nature/i, 5],
    [/has an edge on the .* output/i, 4],
    [/outgoing edges/i, 2],
    [/incoming edges/i, 3],
    [/cannot be reached from the trigger/i, 6],
    [/loops back on itself/i, 7],
    [/trigger nodes?\b/i, 1],
];

/**
 * Which of the seven conditions a reason belongs to, or `null`.
 *
 * @param {string} reason
 * @returns {number|null}
 */
export function classifyReason(reason) {
    const text = String(reason ?? '');

    for (const [pattern, condition] of CLASSIFIERS) {
        if (pattern.test(text)) return condition;
    }

    return null;
}

/**
 * The reasons grouped by the condition they violate, lowest condition first.
 *
 * Grouping is what stops a branched flow with four bad edges from printing the
 * same explanation four times: one heading per condition, the server's own
 * sentences underneath it as the detail.
 *
 * @param {string[]} reasons
 * @returns {Array<{condition: number|null, details: string[]}>}
 */
export function classifyReasons(reasons) {
    const groups = new Map();

    for (const reason of reasons ?? []) {
        const condition = classifyReason(reason);
        const key = condition ?? 'other';

        if (!groups.has(key)) groups.set(key, { condition, details: [] });

        groups.get(key).details.push(String(reason));
    }

    return [...groups.values()].sort((a, b) => (a.condition ?? 99) - (b.condition ?? 99));
}

const UNITS = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

/**
 * A gap in seconds, broken into the units a person reads it in.
 *
 * `172800` → `[{ value: 2, unit: 'day' }]`; `90000` → `[{ value: 1, unit:
 * 'day' }, { value: 1, unit: 'hour' }]`. Zero yields an empty array, which the
 * caller renders as "immediately" rather than as "0 seconds".
 *
 * @param {number} seconds
 * @returns {Array<{value: number, unit: string}>}
 */
export function durationParts(seconds) {
    let rest = Math.max(0, Math.floor(Number(seconds) || 0));
    const parts = [];

    for (const [unit, size] of UNITS) {
        const value = Math.floor(rest / size);

        if (value > 0) {
            parts.push({ value, unit });
            rest -= value * size;
        }
    }

    return parts;
}

/**
 * The order a move-up / move-down produces, or `null` when it would fall off
 * the end.
 *
 * The reorder endpoint wants every mail key exactly once, in the wanted order,
 * and answers 422 to anything that is not a permutation — so this builds the
 * whole list rather than a "swap these two" instruction.
 *
 * @param {string[]} keys
 * @param {number} index
 * @param {number} delta
 * @returns {string[]|null}
 */
export function movedOrder(keys, index, delta) {
    const target = index + delta;

    if (index < 0 || index >= keys.length || target < 0 || target >= keys.length) {
        return null;
    }

    const next = [...keys];
    const [moved] = next.splice(index, 1);
    next.splice(target, 0, moved);

    return next;
}
