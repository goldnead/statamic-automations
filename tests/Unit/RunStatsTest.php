<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActivityWindow;
use Goldnead\StatamicAutomations\Support\RunStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The enrollment funnel, counted out of the runs that were already there.
 */
beforeEach(function (): void {
    $this->stats = app(RunStats::class);

    $this->automation = Automation::create(['name' => 'Series', 'handle' => 'series']);

    $this->run = function (string $status, array $attributes = []): AutomationRun {
        return AutomationRun::create(array_merge([
            'automation_id' => $this->automation->id,
            'automation_uuid' => $this->automation->uuid,
            'status' => $status,
        ], $attributes));
    };
});

it('reads the funnel out of the run rows, with no table of its own', function (): void {
    ($this->run)(AutomationRun::STATUS_WAITING);
    ($this->run)(AutomationRun::STATUS_QUEUED);
    ($this->run)(AutomationRun::STATUS_RUNNING);
    ($this->run)(AutomationRun::STATUS_SUCCESS);
    ($this->run)(AutomationRun::STATUS_SUCCESS);
    ($this->run)(AutomationRun::STATUS_STOPPED);
    ($this->run)(AutomationRun::STATUS_CANCELLED);
    ($this->run)(AutomationRun::STATUS_FAILED);

    expect($this->stats->forAutomation($this->automation->uuid))->toBe([
        'enrolled' => 8,
        'in_progress' => 3,
        'completed' => 2,
        'exited' => 2,
        'failed' => 1,
    ]);
});

it('leaves test runs out, because pressing test is not going through the flow', function (): void {
    ($this->run)(AutomationRun::STATUS_SUCCESS);
    ($this->run)(AutomationRun::STATUS_SUCCESS, ['is_test' => true]);

    expect($this->stats->forAutomation($this->automation->uuid)['completed'])->toBe(1)
        ->and($this->stats->forAutomation($this->automation->uuid, includeTests: true)['completed'])->toBe(2);
});

it('answers for many automations in one query', function (): void {
    // The listing shows a row per automation. Asking per row is how a page
    // with thirty automations makes thirty-one queries.
    $second = Automation::create(['name' => 'Other', 'handle' => 'other']);

    ($this->run)(AutomationRun::STATUS_SUCCESS);
    AutomationRun::create([
        'automation_id' => $second->id,
        'automation_uuid' => $second->uuid,
        'status' => AutomationRun::STATUS_WAITING,
    ]);

    DB::enableQueryLog();

    $all = $this->stats->forAutomations([$this->automation->uuid, $second->uuid]);

    expect(count(DB::getQueryLog()))->toBe(1);

    DB::disableQueryLog();

    expect($all[$this->automation->uuid]['completed'])->toBe(1)
        ->and($all[$second->uuid]['in_progress'])->toBe(1);
});

it('returns zeroes for an automation nobody has been through', function (): void {
    expect($this->stats->forAutomation($this->automation->uuid))->toBe([
        'enrolled' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'exited' => 0,
        'failed' => 0,
    ]);
});

it('counts distinct people separately from enrollments', function (): void {
    // The difference is the whole point of the restart policy: a flow on
    // `always` counts the same person once per re-entry.
    ($this->run)(AutomationRun::STATUS_SUCCESS, ['subject_key' => 'jane@example.com']);
    ($this->run)(AutomationRun::STATUS_SUCCESS, ['subject_key' => 'jane@example.com']);
    ($this->run)(AutomationRun::STATUS_SUCCESS, ['subject_key' => 'bob@example.com']);
    // A run about nobody (a scheduled sweep) is not a person.
    ($this->run)(AutomationRun::STATUS_SUCCESS);

    expect($this->stats->forAutomation($this->automation->uuid)['enrolled'])->toBe(4)
        ->and($this->stats->distinctSubjects($this->automation->uuid))->toBe(2);
});

it('answers exactly as it always did when no window is given', function (): void {
    // The listing screen and the mail list both call it without one, and both
    // mean "ever". A window parameter that quietly became "the last 30 days" by
    // default would change every number on the index page.
    $this->travelTo(now()->subYears(2), function (): void {
        ($this->run)(AutomationRun::STATUS_SUCCESS);
        ($this->run)(AutomationRun::STATUS_FAILED);
    });

    ($this->run)(AutomationRun::STATUS_WAITING);

    expect($this->stats->forAutomation($this->automation->uuid))->toBe([
        'enrolled' => 3,
        'in_progress' => 1,
        'completed' => 1,
        'exited' => 0,
        'failed' => 1,
    ]);
});

it('counts only what falls inside the window when one is given', function (): void {
    $this->travelTo(now()->subDays(45), function (): void {
        ($this->run)(AutomationRun::STATUS_SUCCESS);
    });

    ($this->run)(AutomationRun::STATUS_SUCCESS);

    expect($this->stats->forAutomation($this->automation->uuid, false, ActivityWindow::make('30')))
        ->toBe([
            'enrolled' => 1,
            'in_progress' => 0,
            'completed' => 1,
            'exited' => 0,
            'failed' => 0,
        ])
        ->and($this->stats->forAutomation($this->automation->uuid, false, ActivityWindow::make('90'))['completed'])
        ->toBe(2)
        ->and($this->stats->forAutomation($this->automation->uuid, false, ActivityWindow::make('all'))['completed'])
        ->toBe(2);
});

it('reads a nonsense window as everything rather than refusing it', function (): void {
    // The range arrives in a URL, and a URL is edited by hand and pasted between
    // installs. Answering a typo with a 422 in the middle of a builder page
    // would be worse than showing everything.
    ($this->run)(AutomationRun::STATUS_SUCCESS);

    expect(ActivityWindow::make('last-tuesday')->range)->toBe('all')
        ->and(ActivityWindow::make('last-tuesday')->from)->toBeNull();
});

it('has the composite indexes the two readers need', function (): void {
    // The migration exists because `automation_uuid` and `status` as two
    // separate single-column indexes answer neither question: one narrows to an
    // automation and then scans everything it ever ran, the other narrows to
    // every failed run in the install.
    $indexes = collect(Schema::getIndexes('automation_runs'))->pluck('columns');

    expect($indexes)->toContain(['automation_uuid', 'status'])
        ->and($indexes)->toContain(['automation_uuid', 'subject_key'])
        // And, since the funnel takes a timeframe, the one that carries the
        // date as well. The pair above is its prefix and stays.
        ->and($indexes)->toContain(['automation_uuid', 'status', 'created_at']);
});
