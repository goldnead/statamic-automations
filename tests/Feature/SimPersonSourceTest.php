<?php

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\StatamicAutomations\Integrations\Map\SimPersonSource;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * Which automations a real person has been through, for the dry run's "as it is for person X". Read only.
 */

beforeEach(function () {
    if (! interface_exists(ProvidesSimPersons::class)) {
        $this->markTestSkipped('needs statamic-brand-context 1.25 (person sources)');
    }
});

function spRun(Automation $a, ?string $subject, bool $test = false): void
{
    AutomationRun::query()->create([
        'automation_uuid' => $a->uuid,
        'trigger_type' => 'manual',
        'status' => AutomationRun::STATUS_SUCCESS,
        'subject_key' => $subject,
        'is_test' => $test,
    ]);
}

function spAutomation(string $handle): Automation
{
    return Automation::query()->create(['uuid' => 'uuid-'.$handle, 'name' => ucfirst($handle), 'handle' => $handle, 'enabled' => true]);
}

it('lists the automations a person has been through, by the address her runs carry', function () {
    $welcome = spAutomation('welcome');
    $nurture = spAutomation('nurture');
    spAutomation('never');
    spRun($welcome, 'lena@example.test');
    spRun($welcome, 'lena@example.test');
    spRun($nurture, 'lena@example.test');
    spRun($nurture, 'someone@example.test');

    $facet = app(SimPersonSource::class)->personFacet(['email' => '  Lena@Example.test ', 'id' => 7]);

    expect($facet['ran'])->toEqualCanonicalizing(['welcome', 'nurture']);
});

it('does not count the editor\'s test runs, runs without a subject, or another address', function () {
    $welcome = spAutomation('welcome');
    spRun($welcome, 'lena@example.test', test: true);
    spRun($welcome, null);
    spRun($welcome, 'other@example.test');

    expect(app(SimPersonSource::class)->personFacet(['email' => 'lena@example.test']))->toBe([])
        ->and(app(SimPersonSource::class)->personFacet(['id' => 7]))->toBe([])
        ->and(app(SimPersonSource::class)->personFacet(['email' => '  ']))->toBe([]);
});

it('finds nobody itself: the CRM does', function () {
    $source = app(SimPersonSource::class);

    expect($source->personSourceId())->toBe('automations')
        ->and($source->searchPersons('lena', 8))->toBe([])
        ->and($source->identifyPerson('7'))->toBeNull();
});

it('is guarded by the right to view automations', function () {
    Permission::register('view automations');
    Role::make('viewer')->title('Viewer')->addPermission('view automations')->save();
    $viewer = User::make()->email('v@example.test')->assignRole('viewer');
    $viewer->save();
    $plain = User::make()->email('p@example.test');
    $plain->save();

    $source = app(SimPersonSource::class);

    expect($source->personsAuthorized(null))->toBeFalse()
        ->and($source->personsAuthorized($plain))->toBeFalse()
        ->and($source->personsAuthorized($viewer))->toBeTrue();
});

it('reads without writing, and logs nobody', function () {
    $welcome = spAutomation('welcome');
    spRun($welcome, 'lena@example.test');

    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    $logged = [];
    Log::listen(function ($m) use (&$logged) {
        $logged[] = $m->message;
    });

    app(SimPersonSource::class)->personFacet(['email' => 'lena@example.test', 'id' => 7]);

    expect($writes)->toBe([])
        ->and($logged)->toBe([]);
});
