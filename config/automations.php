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
    | Sub-automations
    |--------------------------------------------------------------------------
    | Maximum nesting depth for the "Call Automation" action, guarding
    | against accidental infinite recursion between automations.
    */

    'max_call_depth' => env('STATAMIC_AUTOMATIONS_MAX_CALL_DEPTH', 3),

    /*
    |--------------------------------------------------------------------------
    | Failure Alerts
    |--------------------------------------------------------------------------
    | Notify someone when a run fails. Channels: "log" and/or "mail".
    | Alerts are throttled per automation by throttle_minutes.
    */

    'alerts' => [
        'enabled' => true,
        'channels' => ['log'],
        'mail_to' => env('STATAMIC_AUTOMATIONS_ALERT_MAIL_TO', null),
        'throttle_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioning
    |--------------------------------------------------------------------------
    | Every save snapshots the automation graph so changes can be rolled
    | back. `keep` caps how many versions are retained per automation.
    */

    'versioning' => [
        'enabled' => true,
        'keep' => 25,
    ],

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
        'call_real_ai' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | Credentials and defaults for the "Generate with AI" action, which calls
    | the Anthropic Claude Messages API. Set the model id to whichever Claude
    | model your plan grants you. Never hard-code the API key in an automation
    | — keep it here (or in the secrets store) and reference it from config.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('STATAMIC_AUTOMATIONS_AI_MODEL', 'claude-sonnet-4-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'max_tokens' => 1024,
        'timeout' => 30,
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
        'ai_action_requires_pro' => true,
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
    | Secrets
    |--------------------------------------------------------------------------
    |
    | Named credentials that automations reference as {{ secret.<name> }}
    | instead of embedding the value in a node config. Pull every value from
    | the environment — never commit a real secret to this file.
    |
    */

    'secrets' => [
        // 'stripe_key' => env('STRIPE_KEY'),
        // 'slack_webhook' => env('SLACK_WEBHOOK_URL'),
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
            // Inbound bridge: the event Webhook Manager fires when it receives
            // a validated inbound request. When this class exists, the
            // "Webhook Received" trigger listens to it. Adjust to match the
            // Webhook Manager version you run.
            'inbound_event' => 'Goldnead\\WebhookManager\\Events\\WebhookReceived',
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
        'loop' => true,
        'parallel' => true,
        'throttle' => true,
        // Actions
        'send_email' => true,
        'send_webhook' => true,
        'add_log_entry' => true,
        'ai_generate' => true,
    ],

];
