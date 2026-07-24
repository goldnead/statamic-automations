<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\DB;

/**
 * Hard brand isolation (multi-brand mode) for statamic-automations.
 *
 * Proves that a HasBrand model created under brand A is invisible from brand
 * B's context, and that the brand-scoped unique lets the same handle live in
 * both brands.
 */
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
});

it('hides an automation created in brand A from brand B', function () {
    $a = BrandContext::runFor($this->brandA, fn () => Automation::create([
        'name' => 'Welcome (A)',
        'handle' => 'welcome',
    ]));

    $b = BrandContext::runFor($this->brandB, fn () => Automation::create([
        'name' => 'Welcome (B)',
        'handle' => 'welcome', // same handle, different brand -> allowed
    ]));

    expect($a->brand_id)->toBe($this->brandA);
    expect($b->brand_id)->toBe($this->brandB);

    // Brand A only ever sees its own automation.
    BrandContext::setCurrent($this->brandA);
    expect(Automation::find($b->id))->toBeNull();
    expect(Automation::find($a->id))->not->toBeNull();
    expect(Automation::count())->toBe(1);

    // Brand B only ever sees its own automation.
    BrandContext::setCurrent($this->brandB);
    expect(Automation::find($a->id))->toBeNull();
    expect(Automation::find($b->id))->not->toBeNull();
    expect(Automation::count())->toBe(1);

    // Cross-brand admin bypass can still reach both.
    expect(Automation::acrossBrands()->count())->toBe(2);
});

it('hides an automation run (execution instance) created in brand A from brand B', function () {
    [$autoA, $runA] = BrandContext::runFor($this->brandA, function () {
        $auto = Automation::create(['name' => 'Flow A', 'handle' => 'flow-a']);
        $run = AutomationRun::create([
            'automation_id' => $auto->id,
            'status' => AutomationRun::STATUS_SUCCESS,
        ]);

        return [$auto, $run];
    });

    $runB = BrandContext::runFor($this->brandB, function () {
        $auto = Automation::create(['name' => 'Flow B', 'handle' => 'flow-b']);

        return AutomationRun::create([
            'automation_id' => $auto->id,
            'status' => AutomationRun::STATUS_SUCCESS,
        ]);
    });

    expect($runA->brand_id)->toBe($this->brandA);
    expect($runB->brand_id)->toBe($this->brandB);

    BrandContext::setCurrent($this->brandB);
    expect(AutomationRun::find($runA->id))->toBeNull();
    expect(AutomationRun::find($runB->id))->not->toBeNull();
    expect(AutomationRun::count())->toBe(1);
});

it('fails closed: no current brand yields no rows in multi-brand mode', function () {
    BrandContext::runFor($this->brandA, fn () => Automation::create([
        'name' => 'A only',
        'handle' => 'a-only',
    ]));

    app('brand-context')->forget();

    expect(Automation::count())->toBe(0);
});
