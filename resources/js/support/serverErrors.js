/**
 * Reading a rejected axios response without losing what the server said.
 *
 * This addon talks to its own JSON API rather than through Inertia, so there
 * is no `onError(errors)` handing a page its error bag: every failure arrives
 * as a thrown axios error, and whatever the page does not dig out of it is
 * gone. Several call sites used to bind the error and then throw a hardcoded
 * string at the user instead — a refused delete said "Delete failed." while
 * the server had said "Permission 'delete automations' is required."
 *
 * These three helpers are the whole contract:
 *
 *   - `errorBag(e)`    — Laravel's `errors` map, flattened to one string per
 *                        key, for showing a message at the field it belongs to
 *   - `errorMessages(e)` — everything the server said, as a flat list, for the
 *                        collected output above a mask
 *   - `firstMessage(e, fallback)` — one line for a toast, preferring a real
 *                        validation message over the generic
 *                        "The given data was invalid."
 */

/** The response body of a rejected request, whatever shape it has. */
function body(e) {
    return e?.response?.data ?? null;
}

/**
 * Laravel's `errors` map with each entry reduced to a single string.
 *
 * Keys stay exactly as the server sent them, dots and all (`nodes.0.type`), so
 * a page can bind one to a field by name and show the rest collected.
 */
export function errorBag(e) {
    const errors = body(e)?.errors;

    if (!errors || typeof errors !== 'object') return {};

    return Object.fromEntries(
        Object.entries(errors)
            .map(([key, value]) => [key, Array.isArray(value) ? value[0] : value])
            .filter(([, message]) => Boolean(message)),
    );
}

/**
 * Every message in the response, flattened.
 *
 * Includes `issues[]`, which this API returns alongside a 422 when an enable
 * is refused. Those carry the per-node reasons and were being dropped: the
 * page checked `data.ok === false` on the *success* path, but axios rejects a
 * 422, so that branch could never run.
 */
export function errorMessages(e) {
    const data = body(e);
    if (!data) return [];

    const fromBag = Object.values(errorBag(e));

    const fromIssues = Array.isArray(data.issues)
        ? data.issues.map((issue) => (typeof issue === 'string' ? issue : issue?.message)).filter(Boolean)
        : [];

    // The generic wrapper is only worth showing when nothing more specific came
    // with it — "The given data was invalid." next to the actual reason is noise.
    const generic = fromBag.length === 0 && fromIssues.length === 0 && data.message
        ? [data.message]
        : [];

    return [...fromBag, ...fromIssues, ...generic];
}

/** One line for a toast: the most specific thing the server said. */
export function firstMessage(e, fallback = null) {
    return errorMessages(e)[0] ?? fallback;
}
