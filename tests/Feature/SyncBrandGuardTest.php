<?php

use Illuminate\Support\Facades\DB;

/**
 * `automations:sync` under multi-brand.
 *
 * Automations are brand-scoped; `resources/automations/` is not. The sync
 * folder is one flat directory of `{handle}.json` with nothing separating
 * brands, and handles are only unique *per brand* — so two brands may each own
 * a `welcome-flow`.
 *
 * Before this guard the command took no brand at all, and a console run has no
 * session, so the multi-brand scope failed closed. That was worse than a no-op:
 * `detectDirection()` asks whether the database has any automations, saw none,
 * concluded the files were the source of truth, and a bare `automations:sync`
 * would import over a database it could not see.
 *
 * The fix refuses rather than guessing which brand a folder belongs to.
 */
function makeBrand(string $handle): int
{
    return DB::table('brands')->insertGetId([
        'handle' => $handle,
        'name' => ucfirst($handle),
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('runs without a brand option on a single-brand install', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    $this->artisan('automations:sync --from=db --dry-run')->assertExitCode(0);
});

it('refuses to sync when several brands exist and none is named', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    makeBrand('sync-a');
    makeBrand('sync-b');

    $this->artisan('automations:sync --from=db --dry-run')
        ->expectsOutputToContain('--brand is required')
        ->assertExitCode(1);
});

it('accepts a named brand', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    makeBrand('named-a');
    makeBrand('named-b');

    $this->artisan('automations:sync --from=db --brand=named-a --dry-run')
        ->expectsOutputToContain('Brand: named-a')
        ->assertExitCode(0);
});

it('rejects an unknown brand rather than falling back', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    makeBrand('real-a');
    makeBrand('real-b');

    $this->artisan('automations:sync --from=db --brand=nope --dry-run')
        ->expectsOutputToContain('No brand [nope]')
        ->assertExitCode(1);
});

it('offers a --brand option', function (): void {
    expect(app('Illuminate\Contracts\Console\Kernel')->all()['automations:sync']
        ->getDefinition()->hasOption('brand'))->toBeTrue();
});
