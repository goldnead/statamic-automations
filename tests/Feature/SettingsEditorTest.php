<?php

use Goldnead\StatamicAutomations\Models\AutomationSetting;
use Goldnead\StatamicAutomations\Support\Settings;
use Statamic\Facades\User;

/**
 * The settings screen writes, and what it writes reaches the config.
 *
 * The screen was read-only until now: it printed `config/automations.php` and
 * told the operator to go and edit a file on the server. Everything here is
 * about the two properties that make the replacement trustworthy — a saved
 * value is the value the rest of the addon reads, and a value returned to its
 * default stops being stored at all.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    // The form always submits every field, so the rules are `present` and a
    // partial payload is a 422 rather than a silent partial write. Tests that
    // care about one key say so, and this fills in the rest from the config.
    $complete = function (array $overrides): array {
        $settings = [];

        foreach (array_keys(Settings::fields()) as $key) {
            $settings[$key] = config('automations.'.$key);
        }

        return array_replace($settings, $overrides);
    };

    $this->patch = fn (array $settings) => $this->patchJson(
        cp_route('statamic-automations.api.settings.update'),
        ['settings' => $complete($settings)],
    );
});

it('stores a changed setting and applies it to the config', function (): void {
    ($this->patch)(['runs.prune_after_days' => 45])->assertOk();

    expect(AutomationSetting::where('key', 'runs.prune_after_days')->first()?->value)->toBe(45)
        ->and(config('automations.runs.prune_after_days'))->toBe(45);
});

it('coerces a number typed into a text field to an integer', function (): void {
    // HTML controls hand back strings, and the retention value is read straight
    // into date arithmetic. A `"45"` there survives until the first strict
    // comparison and then fails somewhere else entirely.
    ($this->patch)(['runs.prune_after_days' => '45'])->assertOk();

    expect(config('automations.runs.prune_after_days'))->toBe(45);
});

it('stores an empty nullable field as null, not as an empty string', function (): void {
    ($this->patch)(['runs.keep_failed_runs_days' => ''])->assertOk();

    expect(config('automations.runs.keep_failed_runs_days'))->toBeNull();
});

it('deletes the override when a value goes back to the default', function (): void {
    ($this->patch)(['runs.prune_after_days' => 45])->assertOk();
    expect(AutomationSetting::count())->toBe(1);

    // Not "stores 30" — stores nothing. A row pinning a value to what it
    // already was would freeze that default across package upgrades.
    ($this->patch)(['runs.prune_after_days' => 30])->assertOk();

    expect(AutomationSetting::count())->toBe(0)
        ->and(config('automations.runs.prune_after_days'))->toBe(30);
});

it('answers with the settings as they now stand, not with what was sent', function (): void {
    // Keyed by the dotted path, flat — the same shape the form indexes by, so
    // the screen can take the answer without knowing the config's nesting.
    $data = ($this->patch)(['runs.keep_failed_runs_days' => '', 'runs.prune_after_days' => '45'])
        ->assertOk()
        ->json('data');

    expect($data['runs.keep_failed_runs_days'])->toBeNull()
        ->and($data['runs.prune_after_days'])->toBe(45);
});

it('saves a list setting as a list', function (): void {
    ($this->patch)(['security.redact_keys' => ['password', ' iban ', '']])->assertOk();

    // Trimmed and compacted: the control is a textarea of lines, and a trailing
    // newline must not become a redaction rule for the empty string.
    expect(config('automations.security.redact_keys'))->toBe(['password', 'iban']);
});

it('refuses a retention of zero days', function (): void {
    ($this->patch)(['runs.prune_after_days' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.runs.prune_after_days']);

    expect(config('automations.runs.prune_after_days'))->toBe(30);
});

it('refuses a queue name that is not there', function (): void {
    ($this->patch)(['queue' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.queue']);
});

it('ignores a key the settings definition does not offer', function (): void {
    // `storage.driver` decides where automations live and is deliberately not
    // editable. A row for it must not be creatable through this endpoint, and
    // must not reach `config()` even if one somehow existed.
    ($this->patch)(['storage.driver' => 'flat_file'])->assertOk();

    expect(AutomationSetting::where('key', 'storage.driver')->exists())->toBeFalse()
        ->and(config('automations.storage.driver'))->toBe('database');
});

it('refuses the write without the settings permission', function (): void {
    $plain = User::make()->email('reader@example.com');
    $plain->save();
    $this->actingAs($plain);

    ($this->patch)(['runs.prune_after_days' => 45])->assertStatus(403);

    expect(AutomationSetting::count())->toBe(0);
});

it('hands the page the form definition and the current values', function (): void {
    ($this->patch)(['runs.prune_after_days' => 45])->assertOk();

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('statamic-automations.settings'))
            ->assertOk()
            ->getContent(),
        true,
    )['props'];

    expect($props['groups'])->toBeArray()->not->toBeEmpty()
        ->and($props['values']['runs.prune_after_days'])->toBe(45)
        ->and($props['canEdit'])->toBeTrue();
});

it('applies stored settings on a fresh boot', function (): void {
    AutomationSetting::create(['key' => 'runs.prune_after_days', 'value' => 45]);

    // The override is read once and cached; a worker booting later must still
    // see it, which is the whole reason `apply()` runs in bootAddon rather than
    // in a Control Panel middleware.
    app(Settings::class)->forget();
    app(Settings::class)->apply();

    expect(config('automations.runs.prune_after_days'))->toBe(45);
});
