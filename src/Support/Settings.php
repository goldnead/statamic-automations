<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * The settings an operator may change from the Control Panel, and the one place
 * that knows what those are.
 *
 * **This class is now a declaration and nothing else.** Until 2026-09-06 it also
 * owned the table, the cache, the boot-time config override and the "back to
 * default" rule — about a thousand lines that `leadhub` and `webhook-manager`
 * each carried a near-identical copy of. All of that moved into
 * `goldnead/statamic-brand-context`, which does it once, for every addon in the
 * suite, and does it *per brand* — the defect this addon shipped with, and
 * documented in its own settings migration: `automation_settings` has no brand
 * column, so on a multi-brand install two brands silently shared one row.
 *
 * What is left is the only part that ever legitimately belonged here: the field
 * list. {@see settingsGroups()} feeds the screen, the validation and the config
 * override alike, so a field cannot appear on screen without a rule behind it.
 *
 * **Overrides, not a copy.** Only keys somebody actually changed are stored.
 * Everything else keeps following `config/automations.php`, so upgrading the
 * package still moves the defaults, and a site that never opens this screen is
 * indistinguishable from one running a release before the screen existed.
 *
 * **What is not here.** The config file has plenty this screen does not offer,
 * and the omissions are deliberate: `storage.driver` decides where automations
 * live and cannot be switched under a running install without moving them
 * first; `ai.api_key` and everything else read from `env()` belongs to the
 * deployment, and putting a secret in the database would take it out of the
 * secret store and into a backup. `integrations` is not a setting at all — it
 * is a detection, and it is shown read-only on the dashboard for that reason.
 */
class Settings implements ProvidesSettings
{
    /**
     * Stable forever: it is written into `brand_settings.namespace` on every
     * row this addon owns, so renaming it orphans every override a site made.
     */
    public static function settingsNamespace(): string
    {
        return 'automations';
    }

    /** The config root unset values keep following: `config/automations.php`. */
    public static function settingsConfigPath(): string
    {
        return 'automations';
    }

    /**
     * Singular `automation`, not `automations`.
     *
     * This is the name this addon has shipped since v2.9 and the one assigned
     * to user groups on live installations. A name derived from the namespace
     * would read `manage automations settings` and would silently stop matching
     * them: the operator loses the screen with nothing anywhere saying why.
     * Registered by this addon's own ServiceProvider, as it always was — the
     * shared layer only asks which permission to check.
     */
    public static function settingsPermission(): string
    {
        return 'manage automation settings';
    }

    /**
     * The editable settings, in the order and grouping the screen shows them.
     *
     * `key` is the path under `automations.`. `type` drives both the control on
     * screen and the validation rule. `nullable` means empty is a real value —
     * `keep_failed_runs_days` unset is "same as the default retention", which is
     * not the same as zero days.
     *
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('Queue'),
                'description' => __('Where automation jobs are dispatched. Changing these affects runs that start after the change; jobs already queued stay where they are.'),
                'fields' => [
                    [
                        'key' => 'queue',
                        'type' => 'string',
                        'label' => __('Queue name'),
                        'description' => __('The queue automation jobs are dispatched onto. A name no worker listens on means automations are enqueued and never run.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'queue_connection',
                        'type' => 'string',
                        'label' => __('Connection'),
                        'description' => __('The queue connection used to dispatch runs. Empty uses the application default.'),
                        'nullable' => true,
                    ],
                ],
            ],
            [
                'title' => __('Runs'),
                'description' => __('How much of each run is kept, and for how long.'),
                'fields' => [
                    [
                        'key' => 'runs.prune_after_days',
                        'type' => 'integer',
                        'label' => __('Retention'),
                        'description' => __('How many days completed runs are kept before they are pruned.'),
                        // Deliberately narrower than the config file, which can
                        // switch pruning off entirely (`PruneRuns` bails at
                        // `days <= 0`, and `null` casts to zero). The screen
                        // will not: unbounded run growth is a foot-gun, and
                        // somebody editing the file by hand is in a different
                        // position from somebody clicking through a form.
                        // Pinned by "it refuses a retention of zero days".
                        'nullable' => false,
                        'min' => 1,
                    ],
                    [
                        'key' => 'runs.keep_failed_runs_days',
                        'type' => 'integer',
                        'label' => __('Failed runs kept for'),
                        'description' => __('How many days failed runs are kept for debugging. Empty keeps them exactly as long as everything else.'),
                        'nullable' => true,
                        'min' => 1,
                    ],
                    [
                        'key' => 'runs.store_full_context',
                        'type' => 'boolean',
                        'label' => __('Store full context'),
                        'description' => __('Persist the full trigger context with each run. Off stores only what the run needs to be read back.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'runs.encrypt_context',
                        'type' => 'boolean',
                        'label' => __('Encrypt context'),
                        'description' => __('Encrypt stored run context at rest. Only affects runs recorded after the change — runs already written stay as they were written.'),
                        'nullable' => false,
                    ],
                ],
            ],
            [
                'title' => __('Test mode'),
                'description' => __('What a test run is allowed to do for real. Everything blocked is simulated instead, and the run says so in its log.'),
                'fields' => [
                    [
                        'key' => 'test_mode.send_real_webhooks',
                        'type' => 'boolean',
                        'label' => __('Send real webhooks'),
                        'description' => __('When blocked, test runs simulate webhook calls instead of sending them.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'test_mode.send_real_emails',
                        'type' => 'boolean',
                        'label' => __('Send real emails'),
                        'description' => __('When blocked, test runs simulate email sends instead of delivering them.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'test_mode.persist_leadhub_changes',
                        'type' => 'boolean',
                        'label' => __('Persist LeadHub changes'),
                        'description' => __('When blocked, test runs do not write changes to LeadHub.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'test_mode.persist_statamic_changes',
                        'type' => 'boolean',
                        'label' => __('Persist Statamic changes'),
                        'description' => __('When blocked, test runs do not write changes to Statamic content.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'test_mode.call_real_ai',
                        'type' => 'boolean',
                        'label' => __('Call the real AI provider'),
                        'description' => __('When blocked, test runs return a canned completion instead of calling (and billing) the provider.'),
                        'nullable' => false,
                    ],
                ],
            ],
            [
                'title' => __('Payload redaction'),
                'description' => __('Run logs replace the values of these keys with [REDACTED] before storing them. Matching is on the key name, so a key that appears at any depth of a payload is covered.'),
                'fields' => [
                    [
                        'key' => 'security.redact_keys',
                        'type' => 'list',
                        'label' => __('Redacted keys'),
                        'description' => __('One key name per line. Emptying this list means payloads are written down exactly as they arrive.'),
                        'nullable' => false,
                    ],
                ],
            ],
        ];
    }
}
