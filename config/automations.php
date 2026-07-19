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
    | Storage Driver (definitions)
    |--------------------------------------------------------------------------
    |
    | Where automation *definitions* (the graph: nodes + edges) live:
    |
    |   - "database"  — Eloquent rows (default). Best for dynamic, CP-driven
    |                   editing on a single environment.
    |   - "flat_file" — one YAML file per automation under `flat_file.path`.
    |                   Best for git-tracked, deploy-as-code workflows.
    |
    | Runtime data (runs, node runs, scheduled jobs, audit log) always lives
    | in the database regardless of this setting. Versioning uses Statamic
    | Revisions (flat files) in both modes.
    |
    */

    'storage' => [
        'driver' => env('STATAMIC_AUTOMATIONS_STORAGE', 'database'), // database | flat_file
        'flat_file' => [
            'path' => env('STATAMIC_AUTOMATIONS_DEFINITIONS_PATH', null), // defaults to resources/automations
        ],
    ],

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
    | back. Snapshots are stored as Statamic Revisions (flat-file YAML under
    | the revisions store), so automation history sits alongside content
    | revisions. `keep` caps how many revisions are retained per automation.
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
    | Pro-tier gating. When the addon is installed from the Statamic
    | Marketplace, licensing is handled natively: the active edition
    | (free | pro) is read via Addon::edition() and the Statamic licensing
    | utility validates it — no settings here are required.
    |
    | The keys below are a fallback for self-hosted / development use only,
    | used when no Marketplace edition is detected. "config" trusts the local
    | allowed_keys; "remote" verifies against a central endpoint.
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
            // Outbound destinations live in Webhook Manager's outbound webhook
            // repository (bound in its service provider), NOT on the facade.
            // The adapter resolves this interface to list destinations and to
            // dispatch by handle. Override only if you run a forked package.
            'outbound_repository' => 'Goldnead\\WebhookManager\\Contracts\\Repositories\\OutboundWebhookRepositoryInterface',
            // The action that performs an actual outbound delivery (snapshot +
            // sync/queue branching). Resolved from the container when present.
            'dispatch_action' => 'Goldnead\\WebhookManager\\Domain\\OutboundWebhook\\Actions\\DispatchOutboundWebhookAction',
            // Inbound bridge: the event Webhook Manager fires when it receives
            // a validated inbound request. When this class exists, the
            // "Webhook Received" trigger listens to it. Adjust to match the
            // Webhook Manager version you run.
            'inbound_event' => 'Goldnead\\WebhookManager\\Events\\WebhookReceived',
        ],
        'leadhub' => [
            // The LeadHub addon's PSR-4 namespace is Goldnead\Leadhub
            // (lowercase "hub"), even though the brand is "LeadHub".
            'detect' => [
                'Goldnead\\Leadhub\\Facades\\LeadHub',
                'Goldnead\\Leadhub\\LeadHubManager',
            ],
            'facade' => [
                'Goldnead\\Leadhub\\Facades\\LeadHub',
            ],
            // When true, write timeline entries on the lead whenever an
            // automation modifies it. Honored by the LeadHub addon.
            'emit_timeline_events' => true,
            // FQCN of the LeadHub event that backs the `contact_score_changed`
            // trigger. Overridable so a future rename plugs in without code.
            'score_changed_event' => 'Goldnead\\Leadhub\\Events\\LeadHubContactScoreChanged',
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
        // Native Statamic operations
        'publish_entry' => true,
        'unpublish_entry' => true,
        'delete_entry' => true,
        'create_term' => true,
        'update_user' => true,
        'assign_user_role' => true,
        'add_user_to_group' => true,
        'set_global_value' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Event Triggers
    |--------------------------------------------------------------------------
    |
    | Turn any application event into an automation trigger without writing a
    | trigger class. Each entry maps an event FQCN to a trigger definition.
    | These appear in the CP trigger list automatically. Closures are not
    | config-serialisable, so this path supports the declarative forms only:
    | `payload` as a dot-path string (or '*' to dump public props) and
    | `matches` as an invokable class-string. For the full-power form (closures)
    | call `Automations::registerEventTrigger()` from a service provider's boot().
    |
    | Example:
    |
    |   \App\Events\OrderShipped::class => [
    |       'handle' => 'order_shipped',
    |       'label' => 'Order Shipped',
    |       'group' => 'Shop',
    |       'payload' => 'order',            // {{ order.id }} etc.
    |       'output_schema' => ['order' => ['id' => 'string', 'total' => 'number']],
    |   ],
    |
    */

    'event_triggers' => [
        //
    ],

];
