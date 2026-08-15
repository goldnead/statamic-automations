<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Models\AutomationSetting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;

/**
 * The settings an operator may change from the Control Panel, and the one place
 * that knows what those are.
 *
 * Three readers share this definition — the boot-time override, the validation
 * on the way in, and the screen that draws the form — so a setting is added by
 * adding one entry to {@see FIELDS} and nothing else falls out of step. The
 * screen in particular is generated from here rather than from a hand-kept list
 * of labels, which is what the read-only version was: a second description of
 * the config file that could quietly disagree with it.
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
 * is a detection, and it stays read-only on the screen for that reason.
 */
class Settings
{
    public function __construct(protected Application $app) {}

    /** Cache key for the stored overrides. */
    public const CACHE_KEY = 'statamic-automations.settings.overrides';

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
    public static function groups(): array
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

    /**
     * Every editable field, flattened.
     *
     * @return array<string, array<string, mixed>> key => field
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (static::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * The stored overrides, keyed by dotted path.
     *
     * Cached because this is read on every boot, including the boot of every
     * queue worker job. A missing table (installed but not migrated) or an
     * unreachable cache is not fatal: no overrides means the config file, which
     * is exactly the behaviour before this screen existed.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => $this->read());
        } catch (\Throwable) {
            try {
                return $this->read();
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /** @return array<string, mixed> */
    protected function read(): array
    {
        return AutomationSetting::query()
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * Push the stored overrides onto the live config.
     *
     * Overriding the config rather than teaching every reader about this class
     * is the whole point: `config('automations.runs.prune_after_days')` is read
     * in a dozen places, and a second source of truth next to it would be one
     * missed call site away from a setting that looks changed and is not.
     */
    public function apply(): void
    {
        // `config:cache` boots the app fully and then dumps the whole
        // resolved config to `bootstrap/cache/config.php`. Applying here
        // during that build would bake the overrides into the cached file,
        // and a baked override outlives the row it came from: deleting a
        // setting would then have no effect at all until somebody ran
        // `config:clear`.
        //
        // It would also poison the baseline below. On the next boot
        // `mergeConfigFrom` is skipped (the config is cached), so
        // `config('automations')` *is* the baked file — the snapshot would
        // record the override as the packaged default, and a value reset to
        // the file's default would be stored as a row instead of deleted,
        // which is precisely the property this class promises.
        //
        // Skipping is safe: the cached config keeps the file values, and
        // every process that reads it applies the overrides on its own boot.
        if (method_exists($this->app, 'runningConsoleCommand') && $this->app->runningConsoleCommand('config:cache')) {
            return;
        }

        // Snapshot what the config *files* say, before anything stored covers
        // it. This is what "back to default" means on this install: the
        // package config as the host published and edited it, not the copy
        // inside the package — a site that changed a default in its own
        // `config/automations.php` must be able to return to that value.
        $this->baseline ??= config('automations', []);

        foreach ($this->overrides() as $key => $value) {
            // Only keys this class offers. A row left behind by an older
            // release must not be able to set an arbitrary config path.
            if (! isset(static::fields()[$key])) {
                continue;
            }

            config()->set('automations.'.$key, $value);
        }
    }

    /**
     * The value on screen for one field: the override if there is one, else
     * whatever the config file says.
     */
    public function value(string $key): mixed
    {
        return config('automations.'.$key);
    }

    /**
     * Write the changed settings.
     *
     * A value equal to the packaged default is *deleted* rather than stored, so
     * "back to default" is reachable from the form and the table does not
     * accumulate rows that pin a value to what it already was.
     *
     * @param  array<string, mixed>  $values  key => value, keys from {@see fields()}
     */
    public function save(array $values): void
    {
        $fields = static::fields();

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            if ($value === $this->packagedDefault($key)) {
                AutomationSetting::query()->where('key', $key)->delete();

                // Put the file's value back on the live config by hand.
                // `apply()` below only writes the overrides that exist, so a
                // deleted one would leave the old value standing in this
                // process — the row is gone, the screen says "default", and
                // everything reading `config()` until the next boot still gets
                // the value that was just taken away.
                config()->set('automations.'.$key, $value);

                continue;
            }

            AutomationSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->forget();
        $this->apply();
    }

    /** Drop the cached overrides so the next read sees the table. */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // No cache store is a running-without-cache install, not a failure
            // to save: `read()` then hits the table on every call anyway.
        }
    }

    /**
     * The config as the files have it, taken before any override was applied.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $baseline = null;

    /**
     * What the config files say, ignoring any override already applied to the
     * live config.
     *
     * Never `config()`: by the time anything asks, {@see apply()} has already
     * overwritten the live value with the override, so comparing against it
     * would report every stored value as equal to the default and delete the
     * lot on the next save. The package file is the last resort, for a call
     * that happens before `apply()` ever ran.
     */
    protected function packagedDefault(string $key): mixed
    {
        if ($this->baseline === null) {
            $this->baseline = require __DIR__.'/../../config/automations.php';
        }

        return data_get($this->baseline, $key);
    }
}
