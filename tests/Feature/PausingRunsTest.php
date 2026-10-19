<?php

use Goldnead\StatamicAutomations\Automations;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\PausesNewsletter;
use Illuminate\Support\Facades\File;

/**
 * "One track at a time": somebody who is inside a flagged sequence does not get
 * the newsletter on top of it.
 *
 * Automations only answers the question; what a broadcast does with the answer
 * is the sender's business. The questions asked here are the ones that decide
 * whether the answer can be trusted: which runs count as inside, which
 * automations count as flagged, and who counts as the same person.
 */
function pausingAutomation(string $handle, bool $flag = true, string $trigger = 'entry_saved'): Automation
{
    $automation = Automation::create([
        'name' => $handle,
        'handle' => $handle,
        'enabled' => true,
    ]);

    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => $trigger,
        'config' => $flag ? [PausesNewsletter::CONFIG_KEY => true] : [],
    ]);

    return $automation;
}

function runFor(Automation $automation, ?string $subject, string $status, bool $test = false): AutomationRun
{
    return AutomationRun::create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'subject_key' => $subject,
        'status' => $status,
        'is_test' => $test,
    ]);
}

it('is off by default and offered as a toggle on every trigger', function (): void {
    $schema = collect(app(NodeRegistry::class)->describe('entry_saved')['schema']);
    $field = $schema->firstWhere('handle', PausesNewsletter::CONFIG_KEY);

    expect($field)->not->toBeNull()
        ->and($field['type'])->toBe('toggle')
        ->and($field['default'])->toBeFalse();
});

it('says yes for somebody in a waiting run of a flagged automation', function (): void {
    runFor(pausingAutomation('welcome-daily'), 'jane@example.com', AutomationRun::STATUS_WAITING);

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeTrue();
});

it('counts a run that has waited for days on a course as inside', function (): void {
    $run = runFor(pausingAutomation('minikurs'), 'jane@example.com', AutomationRun::STATUS_WAITING);
    $run->forceFill(['started_at' => now()->subDays(9)])->save();

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeTrue();
});

it('counts queued and running runs as inside', function (string $status): void {
    runFor(pausingAutomation('welcome-daily'), 'jane@example.com', $status);

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeTrue();
})->with([AutomationRun::STATUS_QUEUED, AutomationRun::STATUS_RUNNING]);

it('does not count a run that has ended', function (string $status): void {
    runFor(pausingAutomation('welcome-daily'), 'jane@example.com', $status);

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeFalse();
})->with([
    AutomationRun::STATUS_SUCCESS,
    AutomationRun::STATUS_STOPPED,
    AutomationRun::STATUS_FAILED,
    AutomationRun::STATUS_CANCELLED,
]);

it('does not count a run of an automation that is not flagged', function (): void {
    runFor(pausingAutomation('stimmanalyse-1', flag: false), 'jane@example.com', AutomationRun::STATUS_WAITING);

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeFalse();
});

it('does not count a test run', function (): void {
    runFor(pausingAutomation('welcome-daily'), 'jane@example.com', AutomationRun::STATUS_WAITING, test: true);

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeFalse();
});

it('does not count somebody who left the sequence while the run is still marked open elsewhere', function (): void {
    // A stopped run of the flagged automation and a waiting run of an
    // unflagged one: neither is a reason to hold the newsletter back.
    runFor(pausingAutomation('welcome-daily'), 'jane@example.com', AutomationRun::STATUS_STOPPED);
    runFor(pausingAutomation('stimmanalyse-1', flag: false), 'jane@example.com', AutomationRun::STATUS_WAITING);

    expect(app(Automations::class)->subjectInPausingRun('jane@example.com'))->toBeFalse();
});

it('finds the person in either of two flagged automations', function (): void {
    $welcome = pausingAutomation('welcome-daily');
    $vokale = pausingAutomation('vokale-strecke');
    runFor($welcome, 'jane@example.com', AutomationRun::STATUS_SUCCESS);
    runFor($vokale, 'jane@example.com', AutomationRun::STATUS_WAITING);
    runFor($welcome, 'john@example.com', AutomationRun::STATUS_WAITING);

    $paused = app(Automations::class)->subjectsInPausingRuns(['jane@example.com', 'john@example.com', 'mary@example.com']);

    expect($paused)->toEqualCanonicalizing(['jane@example.com', 'john@example.com']);
});

it('matches the address regardless of case and surrounding space', function (): void {
    runFor(pausingAutomation('welcome-daily'), 'jane@example.com', AutomationRun::STATUS_WAITING);

    $automations = app(Automations::class);

    expect($automations->subjectInPausingRun('  Jane@Example.COM '))->toBeTrue()
        ->and($automations->subjectsInPausingRuns(['Jane@Example.com']))->toBe(['jane@example.com']);
});

it('does not mistake a run without a subject for anybody', function (): void {
    runFor(pausingAutomation('sweep'), null, AutomationRun::STATUS_WAITING);

    expect(app(Automations::class)->subjectInPausingRun(''))->toBeFalse()
        ->and(app(Automations::class)->subjectsInPausingRuns(['', 'jane@example.com']))->toBe([]);
});

it('answers for a long list in several chunks', function (): void {
    $automation = pausingAutomation('welcome-daily');
    $emails = [];

    foreach (range(1, 1200) as $i) {
        $emails[] = "person{$i}@example.com";
    }

    runFor($automation, 'person3@example.com', AutomationRun::STATUS_WAITING);
    runFor($automation, 'person1199@example.com', AutomationRun::STATUS_WAITING);

    expect(app(Automations::class)->subjectsInPausingRuns($emails))
        ->toEqualCanonicalizing(['person3@example.com', 'person1199@example.com']);
});

it('asks nothing of the database when no automation is flagged', function (): void {
    runFor(pausingAutomation('stimmanalyse-1', flag: false), 'jane@example.com', AutomationRun::STATUS_WAITING);

    expect(app(Automations::class)->pausingAutomationUuids())->toBe([]);
});

it('reads the flag from a flat-file definition and counts the run kept in the database', function (): void {
    $dir = sys_get_temp_dir().'/automations-pause-'.uniqid();
    config()->set('automations.storage.driver', 'flat_file');
    config()->set('automations.storage.flat_file.path', $dir);
    app()->forgetInstance(AutomationRepository::class);

    $repo = app(AutomationRepository::class);

    $flagged = $repo->save(
        new Automation(['name' => 'Welcome', 'handle' => 'welcome-daily', 'enabled' => true]),
        [['node_key' => 't', 'type' => 'manual', 'config' => [PausesNewsletter::CONFIG_KEY => true]]],
        [],
    );
    $plain = $repo->save(
        new Automation(['name' => 'Stimmanalyse', 'handle' => 'stimmanalyse-1', 'enabled' => true]),
        [['node_key' => 't', 'type' => 'manual']],
        [],
    );

    foreach ([[$flagged, 'jane@example.com'], [$plain, 'john@example.com']] as [$automation, $subject]) {
        AutomationRun::create([
            'automation_uuid' => $automation->uuid,
            'subject_key' => $subject,
            'status' => AutomationRun::STATUS_WAITING,
        ]);
    }

    try {
        expect(app(Automations::class)->subjectsInPausingRuns(['jane@example.com', 'john@example.com']))
            ->toBe(['jane@example.com']);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('knows which automations carry the flag', function (): void {
    $welcome = pausingAutomation('welcome-daily');
    pausingAutomation('stimmanalyse-1', flag: false);

    expect(app(Automations::class)->pausingAutomationUuids())->toBe([(string) $welcome->uuid]);
});
