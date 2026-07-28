<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The automation handle is the addon's one business identifier, and since
 * 1.5.0 it is unique per brand: `unique(brand_id, handle)`.
 *
 * These tests ask whether that unique enforces what its name claims — from
 * both sides. Below it, the database must actually refuse a duplicate, which
 * it did not while brand_id could be NULL (a SQL unique does not constrain
 * NULL, so every row without a brand was outside the constraint). Above it,
 * the validator must ask the same question the index answers, which it did
 * not while `Rule::unique()` ran unscoped across every brand's rows.
 */

const UNIQUENESS_MIGRATION = __DIR__
    .'/../../database/migrations/2026_07_28_000003_require_brand_id_on_automations_table.php';

/** Insert straight into the table, the way an import or a data fix does. */
function insertAutomationRow(array $attributes = []): int
{
    return DB::table('automations')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Raw',
        'handle' => 'raw-'.Str::random(6),
        'enabled' => false,
        'version' => 1,
        'brand_id' => app('brand-context')->currentId(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/** Put the column back the way 1.5.3 shipped it, so the defect can be staged. */
function loosenBrandIdOnAutomations(): void
{
    Schema::table('automations', function ($table) {
        $table->unsignedBigInteger('brand_id')->nullable()->change();
    });
}

it('refuses an automation row that carries no brand', function () {
    // This is the whole fix in one assertion. While brand_id was nullable this
    // insert succeeded, and the row it created sat outside the unique: the
    // handle it claimed was constrained for nobody.
    expect(fn () => insertAutomationRow(['brand_id' => null]))
        ->toThrow(QueryException::class);
});

it('refuses a duplicate handle inside one brand even when Eloquent is bypassed', function () {
    $brandId = app('brand-context')->currentId();

    insertAutomationRow(['handle' => 'welcome', 'brand_id' => $brandId]);

    expect(fn () => insertAutomationRow(['handle' => 'welcome', 'brand_id' => $brandId]))
        ->toThrow(QueryException::class);
});

it('still lets two brands hold the same handle', function () {
    // The tenant boundary must survive the tightening: NOT NULL closes the
    // hole under the unique, it does not turn the unique back into a global
    // one. Same handle, two brands, both accepted.
    $other = DB::table('brands')->insertGetId([
        'handle' => 'other',
        'name' => 'Other',
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    insertAutomationRow(['handle' => 'welcome']);
    insertAutomationRow(['handle' => 'welcome', 'brand_id' => $other]);

    expect(DB::table('automations')->where('handle', 'welcome')->count())->toBe(2);
});

it('stamps, disambiguates and tightens when an existing install upgrades', function () {
    // Reproduce a 1.5.x install: the column is nullable, and rows without a
    // brand have accumulated duplicate handles because nothing stopped them.
    loosenBrandIdOnAutomations();

    $first = insertAutomationRow(['handle' => 'welcome', 'name' => 'First', 'brand_id' => null]);
    $second = insertAutomationRow(['handle' => 'welcome', 'name' => 'Second', 'brand_id' => null]);

    // The defect itself, asserted before it is repaired: two rows, one handle.
    expect(DB::table('automations')->where('handle', 'welcome')->count())->toBe(2);

    (require UNIQUENESS_MIGRATION)->up();

    $defaultId = app('brand-context')->defaultId();

    expect(DB::table('automations')->whereNull('brand_id')->count())->toBe(0)
        ->and(DB::table('automations')->where('id', $first)->value('brand_id'))->toBe($defaultId)
        ->and(DB::table('automations')->where('id', $second)->value('brand_id'))->toBe($defaultId);

    // Neither automation is deleted; the second keeps a handle of its own.
    expect(DB::table('automations')->where('id', $first)->value('handle'))->toBe('welcome')
        ->and(DB::table('automations')->where('id', $second)->value('handle'))->toBe('welcome-'.$second);

    // And the hole is closed afterwards.
    expect(fn () => insertAutomationRow(['brand_id' => null]))->toThrow(QueryException::class);
});

it('is a no-op when run twice', function () {
    insertAutomationRow(['handle' => 'welcome']);

    (require UNIQUENESS_MIGRATION)->up();
    (require UNIQUENESS_MIGRATION)->up();

    expect(DB::table('automations')->where('handle', 'welcome')->count())->toBe(1);
});

describe('handle validation across brands', function () {
    beforeEach(function () {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $this->brandA = DB::table('brands')->insertGetId([
            'handle' => 'brand-a',
            'name' => 'Brand A',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->brandB = DB::table('brands')->insertGetId([
            'handle' => 'brand-b',
            'name' => 'Brand B',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsSuperUser();
    });

    it('lets a brand claim a handle another brand already uses', function () {
        BrandContext::runFor($this->brandA, fn () => Automation::create([
            'name' => 'Welcome (A)',
            'handle' => 'welcome',
        ]));

        BrandContext::setCurrent($this->brandB);

        // The schema has allowed this since 1.5.0. `Rule::unique()` runs on the
        // raw query builder, which no global scope reaches, so before the fix
        // this came back 422 — refused on the strength of a row brand B is not
        // permitted to see, and told so.
        $this->postJson(cp_route('statamic-automations.api.automations.store'), [
            'name' => 'Welcome (B)',
            'handle' => 'welcome',
        ])->assertSuccessful();

        expect(Automation::acrossBrands()->where('handle', 'welcome')->count())->toBe(2);
    });

    it('still refuses the same handle twice inside one brand', function () {
        BrandContext::runFor($this->brandA, fn () => Automation::create([
            'name' => 'Welcome (A)',
            'handle' => 'welcome',
        ]));

        BrandContext::setCurrent($this->brandA);

        $this->postJson(cp_route('statamic-automations.api.automations.store'), [
            'name' => 'Welcome again',
            'handle' => 'welcome',
        ])->assertStatus(422)->assertJsonValidationErrors('handle');
    });

    it('lets an automation keep its own handle on update', function () {
        $automation = BrandContext::runFor($this->brandA, fn () => Automation::create([
            'name' => 'Welcome (A)',
            'handle' => 'welcome',
        ]));

        BrandContext::setCurrent($this->brandA);

        $this->patchJson(
            cp_route('statamic-automations.api.automations.update', ['automation' => $automation->id]),
            ['name' => 'Renamed', 'handle' => 'welcome']
        )->assertSuccessful();
    });
});
