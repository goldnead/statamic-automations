<?php

/**
 * A Control Panel that survives the install it was given.
 *
 * On 03.09.2026 sibling addons answered HTTP 500 on the public demo because
 * they shipped and their migrations had never run — `no such table`, thrown by
 * the first query of the listing. These tests put every index page in front of
 * exactly that database and hold it to two things: an empty state instead of
 * the crash, and a line in the log saying why.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

/**
 * Run something against a database on which nothing has ever been migrated.
 *
 * An empty second connection rather than `Schema::drop()` on the real one, and
 * the reason is worth writing down: testbench rolls its registered migrations
 * back when the application is torn down, and a `down()` cannot reverse a table
 * that is no longer there. Dropping leaves every case after the first one
 * failing in teardown instead of in its assertion — the worst place to look for
 * it. An empty database is also the more faithful picture of the install this
 * whole feature exists for: the addon is there, the tables never were.
 */
function withUnmigratedDatabase(callable $work): mixed
{
    config()->set('database.connections.unmigrated', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $previous = (string) config('database.default');

    config()->set('database.default', 'unmigrated');
    DB::setDefaultConnection('unmigrated');

    try {
        return $work();
    } finally {
        config()->set('database.default', $previous);
        DB::setDefaultConnection($previous);
        DB::purge('unmigrated');
    }
}

/**
 * The Inertia page object behind a CP response.
 *
 * Asked for as an Inertia XHR, exactly as CpRoutesTest does: the full-page
 * response would need the host application's root view, which the test bed's
 * skeleton does not have.
 */
function automationsSetupPage(string $route): array
{
    $response = test()->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route));

    $response->assertOk();

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

dataset('guarded index pages', [
    'automations' => [
        'statamic-automations.automations.index',
        'automation_runs',
        'statamic-automations::Automations/Index',
    ],
    'dashboard' => [
        'statamic-automations.dashboard',
        'automation_runs',
        'statamic-automations::Dashboard',
    ],
    'runs' => [
        'statamic-automations.runs.index',
        'automation_runs',
        'statamic-automations::Runs/Index',
    ],
    'rules' => [
        'statamic-automations.rules.index',
        'automation_nodes',
        'statamic-automations::Rules/Index',
    ],
    'audit' => [
        'statamic-automations.audit',
        'automation_audit_logs',
        'statamic-automations::Audit/Index',
    ],
]);

it('answers 200 rather than 500 when its tables are missing', function (string $route): void {
    withUnmigratedDatabase(
        fn () => $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route))->assertOk()
    );
})->with('guarded index pages');

it('renders the setup screen and names the missing table', function (string $route, string $table): void {
    $page = withUnmigratedDatabase(fn () => automationsSetupPage($route));

    expect($page['component'])->toBe('statamic-automations::SetupRequired')
        ->and($page['props']['tables'])->toContain($table)
        ->and($page['props']['heading'])->not->toBeEmpty()
        ->and($page['props']['description'])->not->toBeEmpty()
        ->and($page['props']['title'])->not->toBeEmpty();
})->with('guarded index pages');

/**
 * The point of the guard is a readable page, not a quiet one. If this goes red
 * the addon has traded a visible 500 for a silent nothing, and an install that
 * looks finished but never works is the worse of the two.
 */
it('writes the reason to the log', function (string $route): void {
    Log::spy();

    withUnmigratedDatabase(
        fn () => $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route))->assertOk()
    );

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'automations')
            && str_contains($message, 'php artisan migrate'))
        ->once();
})->with('guarded index pages');

it('still renders the listing on a migrated install', function (string $route, string $table, string $component): void {
    expect(automationsSetupPage($route)['component'])->toBe($component);
})->with('guarded index pages');

/**
 * A flat-file install is not an unfinished one.
 *
 * With `automations.storage.driver` on `flat_file` the definitions live in
 * YAML and the `automations` table is never read, so the two screens that go
 * through the repository must not name it. They still need `automation_runs`,
 * which has no flat driver — the guard gets narrower, not empty.
 */
it('leaves the definition table out of the guard on a flat-file install', function (): void {
    config()->set('automations.storage.driver', 'flat_file');

    $page = withUnmigratedDatabase(fn () => automationsSetupPage('statamic-automations.dashboard'));

    expect($page['component'])->toBe('statamic-automations::SetupRequired')
        ->and($page['props']['tables'])->toContain('automation_runs')
        ->and($page['props']['tables'])->not->toContain('automations');
});
