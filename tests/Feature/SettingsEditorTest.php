<?php

use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicAutomations\Support\Settings;
use Statamic\Facades\User;

/**
 * The settings screen writes, and what it writes reaches the config.
 *
 * The screen itself is no longer this addon's. Since 2026-09-06 the store, the
 * validation, the controller and the Vue page live in
 * `goldnead/statamic-brand-context`, which does all four once for the whole
 * suite and does them per brand — the defect this addon shipped with, because
 * `automation_settings` had no brand column.
 *
 * What is still this addon's, and what this file therefore tests, is the
 * declaration: the namespace, the config root, the permission name, and the
 * field list with its types and bounds. The end-to-end assertions are kept
 * rather than handed to brand-context's own suite, because "the shared layer
 * works" and "this addon is correctly plugged into it" are different
 * statements, and only the second one catches a wrong namespace or a
 * permission renamed by accident.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->url = cp_route('brand-context.settings.update');

    // The form always submits every field of one namespace, so the rules are
    // `present` and a partial payload is a 422 rather than a silent partial
    // write. Tests that care about one key say so, and this fills in the rest
    // from the config.
    $complete = function (array $overrides): array {
        $settings = [];

        foreach (array_keys(app(SettingsRegistry::class)->fields('automations')) as $key) {
            $settings[$key] = config('automations.'.$key);
        }

        return array_replace($settings, $overrides);
    };

    $this->patch = fn (array $settings) => $this->patchJson($this->url, [
        'namespace' => 'automations',
        'settings' => $complete($settings),
    ]);
});

it('registers itself with the shared settings layer', function (): void {
    $registry = app(SettingsRegistry::class);

    expect($registry->has('automations'))->toBeTrue('bootAddon() did not register the settings provider')
        ->and($registry->provider('automations'))->toBe(Settings::class)
        ->and($registry->configPath('automations'))->toBe('automations');
});

it('declares the permission it already ships, not one derived from the namespace', function (): void {
    // Singular `automation`. `manage automations settings` would be a new name
    // that no user group on any live install has been granted, and the operator
    // would lose the screen with nothing saying why.
    expect(app(SettingsRegistry::class)->permission('automations'))
        ->toBe('manage automation settings');
});

it('stores a changed setting and applies it to the config', function (): void {
    ($this->patch)(['runs.prune_after_days' => 45])->assertRedirect();

    $row = BrandSetting::query()->where('key', 'runs.prune_after_days')->first();

    expect($row?->value)->toBe(45)
        // The row is stamped with a brand, which is the whole reason for the
        // move: `automation_settings` had no such column.
        ->and($row?->namespace)->toBe('automations')
        ->and($row?->brand_id)->not->toBeNull()
        ->and(config('automations.runs.prune_after_days'))->toBe(45);
});

it('coerces a number typed into a text field to an integer', function (): void {
    // HTML controls hand back strings, and the retention value is read straight
    // into date arithmetic. A `"45"` there survives until the first strict
    // comparison and then fails somewhere else entirely.
    ($this->patch)(['runs.prune_after_days' => '45'])->assertRedirect();

    expect(config('automations.runs.prune_after_days'))->toBe(45);
});

it('stores an empty nullable field as null, not as an empty string', function (): void {
    ($this->patch)(['runs.keep_failed_runs_days' => ''])->assertRedirect();

    expect(config('automations.runs.keep_failed_runs_days'))->toBeNull();
});

it('deletes the override when a value goes back to the default', function (): void {
    ($this->patch)(['runs.prune_after_days' => 45])->assertRedirect();
    expect(BrandSetting::query()->where('namespace', 'automations')->count())->toBe(1);

    // Not "stores 30" — stores nothing. A row pinning a value to what it
    // already was would freeze that default across package upgrades.
    ($this->patch)(['runs.prune_after_days' => 30])->assertRedirect();

    expect(BrandSetting::query()->where('namespace', 'automations')->count())->toBe(0)
        ->and(config('automations.runs.prune_after_days'))->toBe(30);
});

it('saves a list setting as a list', function (): void {
    ($this->patch)(['security.redact_keys' => ['password', ' iban ', '']])->assertRedirect();

    // Trimmed and compacted: the control is a textarea of lines, and a trailing
    // newline must not become a redaction rule for the empty string.
    expect(config('automations.security.redact_keys'))->toBe(['password', 'iban']);
});

it('refuses a retention of zero days', function (): void {
    // `min => 1` in this addon's field list, deliberately narrower than the
    // config file, which can switch pruning off entirely. Unbounded run growth
    // is a foot-gun, and somebody editing the file by hand is in a different
    // position from somebody clicking through a form.
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
    ($this->patch)(['storage.driver' => 'flat_file'])->assertRedirect();

    expect(BrandSetting::query()->where('key', 'storage.driver')->exists())->toBeFalse()
        ->and(config('automations.storage.driver'))->toBe('database');
});

it('refuses the write without the settings permission', function (): void {
    $plain = User::make()->email('reader@example.com');
    $plain->save();
    $this->actingAs($plain);

    ($this->patch)(['runs.prune_after_days' => 45])->assertStatus(403);

    expect(BrandSetting::query()->acrossBrands()->count())->toBe(0);
});

it('hands the shared screen this addon as one section, with the current values', function (): void {
    ($this->patch)(['runs.prune_after_days' => 45])->assertRedirect();

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('brand-context.settings.index'))
            ->assertOk()
            ->getContent(),
        true,
    )['props'];

    $section = collect($props['sections'])->firstWhere('namespace', 'automations');

    expect($section)->not->toBeNull('the automations section is missing from the suite settings screen')
        ->and($section['groups'])->toBeArray()->not->toBeEmpty()
        ->and($section['values']['runs.prune_after_days'])->toBe(45);
});

it('applies stored settings on a fresh boot', function (): void {
    BrandSetting::query()->create([
        'namespace' => 'automations',
        'key' => 'runs.prune_after_days',
        'value' => 45,
    ]);

    // The override is read once and cached; a worker booting later must still
    // see it, which is the whole reason the shared layer applies in a `booted`
    // callback rather than in a Control Panel middleware.
    $settings = app(SettingsManager::class);
    $settings->forget('automations');
    $settings->apply(force: true);

    expect(config('automations.runs.prune_after_days'))->toBe(45);
});

it('keeps the old settings URL working', function (): void {
    // Operators have it bookmarked and this addon has printed it since v2.9. A
    // 404 on a URL that used to work reads as a broken install, not as a moved
    // page.
    $this->get(cp_route('statamic-automations.settings'))
        ->assertRedirect(cp_route('brand-context.settings.index'));
});
