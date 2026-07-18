/**
 * Central node-handle → Statamic CP icon mapping.
 *
 * Shared by NodeCard (canvas) and NodeLibrary (palette) so a given node type
 * always shows the same icon in both places. Every name below is a real icon
 * that ships with `@statamic/cms` (`resources/svg/icons/*.svg`) — no invented
 * names. Unknown handles fall back to a per-kind default.
 */

// Verified against vendor/statamic/cms/resources/svg/icons.
const HANDLE_ICONS = {
    // Triggers
    entry_published: 'entry',
    entry_saved: 'save',
    entry_deleted: 'trash',
    user_registered: 'add-user',
    form_submitted: 'forms',
    webhook_received: 'globe-world-wide-web',
    scheduled: 'time-clock',
    manual: 'cursor-click',

    // Logic
    filter: 'filter',
    branch: 'triangle-arrow-split-vertical-up-2',
    switch: 'hierarchy',
    parallel: 'columns',
    wait_until: 'time-now',
    delay: 'time-clock',
    throttle: 'sliders-horizontal',
    loop: 'sync',
    stop: 'x-square',

    // Actions
    send_email: 'mail',
    send_webhook: 'share-link',
    add_log_entry: 'file-content-list',
    set_variable: 'programming-script-code-brackets',
    update_entry: 'edit',
    create_entry: 'add-entry',
    create_user: 'add-user',
    call_automation: 'workflow',
    ai_generate: 'ai-spark',
};

const KIND_FALLBACK = {
    trigger: 'flash-bolt-lightning',
    logic: 'hierarchy',
    action: 'node-connect',
};

/**
 * @param {string} handle Node type handle (e.g. 'send_email').
 * @param {string} kind   'trigger' | 'logic' | 'action' (fallback bucket).
 * @returns {string} A valid Statamic icon name.
 */
export function nodeIcon(handle, kind = 'action') {
    return HANDLE_ICONS[handle] ?? KIND_FALLBACK[kind] ?? 'node-connect';
}

export function useNodeIcon() {
    return { nodeIcon };
}
