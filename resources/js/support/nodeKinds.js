/**
 * What a node is, in this addon, expressed as data for the shared editor.
 *
 * `@goldnead/flow-canvas` knows how to draw a graph and nothing about what the
 * boxes mean. This file is the whole of what makes its canvas an *automation*
 * canvas: three kinds, their colours, and the rule that a flow has exactly one
 * trigger.
 */

import { createNodeIcon } from '@goldnead/flow-canvas';

export const NODE_KINDS = {
    trigger: {
        label: __('Trigger'),
        plural: __('Triggers'),
        group: 'triggers',
        color: 'blue',
        // One per flow. That is also why it cannot be duplicated and why it
        // offers "Replace trigger" instead of "Delete": deleting the only
        // trigger leaves a flow nothing can start.
        unique: true,
        hasInput: false,
        replaceLabel: __('Replace trigger'),
    },
    logic: {
        label: __('Logic'),
        plural: __('Logic'),
        group: 'logic',
        color: 'amber',
    },
    action: {
        label: __('Action'),
        plural: __('Actions'),
        group: 'actions',
        color: 'emerald',
        // Where an unrecognised node type lands.
        fallback: true,
    },
};

export const ADDER_LABELS = {
    root: __('Add a trigger'),
    step: __('Add a step'),
};

export const PICK_LABELS = {
    entry: __('Choose a trigger to start the flow.'),
    replaceEntry: __('Choose a trigger to replace the current one.'),
    step: __('Choose a node to insert here.'),
};

/**
 * Node handle → icon. Every name is a real icon shipped by `@statamic/cms`
 * (`resources/svg/icons/*.svg`); an invented one renders as nothing.
 */
export const nodeIcon = createNodeIcon({
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
}, {
    trigger: 'flash-bolt-lightning',
    logic: 'hierarchy',
    action: 'node-connect',
});
