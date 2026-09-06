<?php

use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The move from `automation_settings` to `brand_settings`.
 *
 * This is the one part of the settings rework that cannot be re-run by hand if
 * it is wrong: an operator who upgrades and finds their retention back at the
 * packaged default has no way of knowing a value was lost, only that the site
 * behaves differently.
 *
 * The migration is invoked directly rather than through `artisan migrate`,
 * because `RefreshDatabase` has already run it against the empty table by the
 * time a test body starts. Calling `up()` on a populated table is exactly the
 * case the migration exists for, and calling it twice is the case a rerun and
 * a partially-failed batch both produce.
 */
$migration = fn () => require __DIR__.'/../../database/migrations/2026_09_06_000100_move_automation_settings_to_brand_settings.php';

$defaultBrandId = fn () => DB::table('brands')
    ->where('handle', config('brand-context.default_handle', 'default'))
    ->value('id');

it('carries a stored override onto the default brand and into the config', function () use ($migration, $defaultBrandId): void {
    DB::table('automation_settings')->insert([
        'key' => 'runs.prune_after_days',
        'value' => json_encode(45),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    $row = BrandSetting::query()->acrossBrands()
        ->where('namespace', 'automations')
        ->where('key', 'runs.prune_after_days')
        ->first();

    expect($row)->not->toBeNull('the override did not reach brand_settings')
        ->and($row->brand_id)->toBe((int) $defaultBrandId())
        ->and($row->value)->toBe(45);

    // And the shared layer serves it, which is the only statement that matters
    // to the running site — a row in the right table that never reaches
    // `config()` is the same outcome as a lost setting.
    $settings = app(SettingsManager::class);
    $settings->forget('automations');
    $settings->apply(force: true);

    expect(config('automations.runs.prune_after_days'))->toBe(45);
});

it('carries every value type across without changing it', function () use ($migration): void {
    // A boolean false, an empty list and a null are the three values most
    // easily mistaken for "nothing stored" by a copy that decodes and re-encodes
    // on the way through.
    DB::table('automation_settings')->insert([
        ['key' => 'runs.store_full_context', 'value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'security.redact_keys', 'value' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'runs.keep_failed_runs_days', 'value' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'queue', 'value' => json_encode('automations/high'), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration()->up();

    $values = BrandSetting::query()->acrossBrands()
        ->where('namespace', 'automations')
        ->pluck('value', 'key')
        ->all();

    expect($values['runs.store_full_context'])->toBeFalse()
        ->and($values['security.redact_keys'])->toBe([])
        ->and($values['runs.keep_failed_runs_days'])->toBeNull()
        ->and($values['queue'])->toBe('automations/high');
});

it('is safe to run twice and does not overwrite a newer value', function () use ($migration): void {
    DB::table('automation_settings')->insert([
        'key' => 'runs.prune_after_days',
        'value' => json_encode(45),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    // The operator changes the setting on the new screen after the upgrade. A
    // second run of the migration — a rerun, or a batch that aborted after this
    // file — must not put the old value back.
    BrandSetting::query()->acrossBrands()
        ->where('namespace', 'automations')
        ->where('key', 'runs.prune_after_days')
        ->update(['value' => json_encode(7)]);

    $migration()->up();

    $rows = BrandSetting::query()->acrossBrands()
        ->where('namespace', 'automations')
        ->where('key', 'runs.prune_after_days')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->value)->toBe(7);
});

it('leaves the old table in place so a rollback still finds the settings', function () use ($migration): void {
    DB::table('automation_settings')->insert([
        'key' => 'queue',
        'value' => json_encode('automations/high'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    // Dropping it in the same release that stops reading it would mean a
    // `migrate:rollback` comes up with every setting silently back at its
    // packaged default. It goes in the next minor, not this one.
    expect(Schema::hasTable('automation_settings'))->toBeTrue()
        ->and(DB::table('automation_settings')->where('key', 'queue')->exists())->toBeTrue();
});

it('takes back what it carried over and nothing else', function () use ($migration, $defaultBrandId): void {
    DB::table('automation_settings')->insert([
        'key' => 'runs.prune_after_days',
        'value' => json_encode(45),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    // A setting made on the new screen after the upgrade. It has no row in the
    // old table, so `down()` has no business deleting it.
    BrandSetting::query()->create([
        'namespace' => 'automations',
        'key' => 'queue',
        'value' => 'automations/high',
    ]);

    $migration()->down();

    $keys = BrandSetting::query()->acrossBrands()
        ->where('namespace', 'automations')
        ->pluck('key')
        ->all();

    expect($keys)->toBe(['queue'])
        ->and($defaultBrandId())->not->toBeNull();
});
