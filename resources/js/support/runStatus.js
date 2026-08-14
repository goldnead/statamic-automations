/**
 * One badge colour per run / node-run status.
 *
 * This map existed three times — Runs/Index.vue, Runs/Show.vue and
 * RunLogPanel.vue — each carrying the subset of statuses its own screen
 * happened to render, and each falling through to `default` for the rest. That
 * made them behaviourally identical and textually different, which is the shape
 * where one copy gets a new status and the others quietly keep painting it grey.
 *
 * The union is here. Adding a status to the engine is now one edit, and every
 * screen that shows runs agrees about what it looks like.
 */
const COLORS = {
    success: 'green',
    failed: 'red',
    stopped: 'amber',
    cancelled: 'default',
    running: 'blue',
    waiting: 'blue',
    queued: 'default',
    pending: 'default',
    skipped: 'default',
};

export function statusColor(status) {
    return COLORS[status] ?? 'default';
}
