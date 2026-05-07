<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Automation runs are dispatched to a queue so that Statamic events,
    | form submissions and CP actions are never blocked. You may use a
    | dedicated queue connection here.
    |
    */

    'queue' => env('STATAMIC_AUTOMATIONS_QUEUE', 'default'),

    'queue_connection' => env('STATAMIC_AUTOMATIONS_QUEUE_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Run Storage
    |--------------------------------------------------------------------------
    */

    'runs' => [
        'store_context' => true,
        'store_full_context' => true,
        'store_node_io' => true,
        'prune_after_days' => 30,
        'keep_failed_runs_days' => null, // null = same as prune_after_days

        // When true, AutomationRun.context and AutomationNodeRun.input/output
        // are encrypted at rest with Laravel Crypt (uses APP_KEY). Reads are
        // transparent — the API response shape is unchanged. Pre-existing
        // unencrypted rows continue to work after enabling.
        'encrypt_context' => env('STATAMIC_AUTOMATIONS_ENCRYPT_CONTEXT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Mode
    |--------------------------------------------------------------------------
    |
    | When an automation is run in test mode the engine should not produce
    | real side effects by default. Each toggle below controls whether a
    | particular type of side effect is allowed during a test run.
    |
    */

    'test_mode' => [
        'send_real_webhooks' => false,
        'send_real_emails' => false,
        'persist_leadhub_changes' => false,
        'persist_statamic_changes' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */

    'features' => [
        'branch_nodes' => true,
        'filter_nodes' => true,
        'delay_nodes' => true,
        'custom_actions' => true,
        'custom_actions_requires_pro' => true,
        'custom_triggers' => true,
        'templates' => true,
        'export_import' => true,
        'file_storage' => true,
        'multisite' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage
    |--------------------------------------------------------------------------
    |
    | When enabled, individual automations can be exported to JSON files
    | inside the configured directory. Database remains the runtime
    | source of truth — files are a portable representation suited for
    | version control, starter kits and cross-environment migration.
    |
    */

    'file_storage' => [
        'enabled' => true,
        'path' => env('STATAMIC_AUTOMATIONS_FILE_PATH', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Keys that match these patterns (case-insensitive) will have their
    | values redacted before being stored in run logs or context payloads.
    |
    */

    'security' => [
        'redact_keys' => [
            'password',
            'passwort',
            'token',
            'secret',
            'api_key',
            'authorization',
            'credit_card',
            'card_number',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Licensing
    |--------------------------------------------------------------------------
    |
    | Optional Pro-tier licensing. The default mode is "config" which
    | keeps everything local — set `mode = remote` if you operate a
    | central license endpoint.
    |
    */

    'license' => [
        'key' => env('STATAMIC_AUTOMATIONS_LICENSE_KEY', ''),
        'mode' => env('STATAMIC_AUTOMATIONS_LICENSE_MODE', 'config'), // config | remote
        'endpoint' => env('STATAMIC_AUTOMATIONS_LICENSE_ENDPOINT', ''),
        'cache_ttl_minutes' => 360,
        // Used when mode = config; any of these keys grants Pro access.
        'allowed_keys' => [],
        // Default feature handles unlocked for "config" mode validations.
        'features' => ['custom_actions', 'custom_triggers'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional Integrations
    |--------------------------------------------------------------------------
    */

    'integrations' => [
        'webhook_manager' => [
            // Class names checked by IntegrationDetector. The first
            // class that exists wins — leave the defaults in place
            // unless you ship a forked Webhook Manager package.
            'detect' => [
                'Goldnead\\WebhookManager\\Facades\\WebhookManager',
                'Goldnead\\WebhookManager\\WebhookManager',
            ],
            'facade' => [
                'Goldnead\\WebhookManager\\Facades\\WebhookManager',
            ],
        ],
        'leadhub' => [
            'detect' => [
                'Goldnead\\LeadHub\\Facades\\LeadHub',
                'Goldnead\\LeadHub\\LeadHub',
            ],
            'facade' => [
                'Goldnead\\LeadHub\\Facades\\LeadHub',
            ],
            // When true, write timeline entries on the lead whenever an
            // automation modifies it. Honored by the LeadHub addon.
            'emit_timeline_events' => true,
        ],
    ],

    'builtin_nodes' => [
        // Triggers
        'manual' => true,
        'form_submitted' => true,
        'entry_published' => true,
        // Logic
        'filter' => true,
        'branch' => true,
        'stop' => true,
        'delay' => true,
        // Actions
        'send_email' => true,
        'send_webhook' => true,
        'add_log_entry' => true,
    ],

];
