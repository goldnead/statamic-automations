<?php

use Goldnead\StatamicAutomations\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;

/**
 * The public way to close the passes a graph edit has stranded.
 *
 * Marketing shortens a sequence and the step a sleeping run was heading for
 * disappears. The wake-up call and the run have to go together: one without
 * the other leaves either a zombie run or a call that fires into nothing. The
 * method is public so that a sibling does not have to write into these tables.
 */
beforeEach(function (): void {
    $this->automation = Automation::create([
        'name' => 'Sequenz',
        'handle' => 'sequenz_'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    $this->run = fn (string $status, ?Automation $on = null) => AutomationRun::create([
        'automation_id' => ($on ?? $this->automation)->id,
        'automation_uuid' => ($on ?? $this->automation)->uuid,
        'subject_key' => 'jane@example.com',
        'status' => $status,
    ]);

    $this->job = fn (string $nodeKey, ?AutomationRun $run, string $status = AutomationScheduledJob::STATUS_PENDING, ?Automation $on = null) => AutomationScheduledJob::create([
        'automation_id' => ($on ?? $this->automation)->id,
        'automation_run_id' => $run?->id,
        'node_key' => $nodeKey,
        'due_at' => now()->addDays(2),
        'status' => $status,
        'payload' => [],
    ]);
});

it('cancels the wake-up call and the run when the node it waits for is gone', function (): void {
    $run = ($this->run)(AutomationRun::STATUS_WAITING);
    $job = ($this->job)('delay_2', $run);

    $count = app(Automations::class)->cancelRunsOnMissingNodes(
        $this->automation,
        ['t', 'mail_1'],
        'the step was removed',
    );

    expect($count)->toBe(1)
        ->and($job->fresh()->status)->toBe(AutomationScheduledJob::STATUS_CANCELLED)
        ->and($run->fresh()->status)->toBe(AutomationRun::STATUS_CANCELLED)
        ->and($run->fresh()->error_message)->toContain('the step was removed')
        ->and($run->fresh()->finished_at)->not->toBeNull();
});

it('leaves a call alone whose node survives', function (): void {
    $run = ($this->run)(AutomationRun::STATUS_WAITING);
    $job = ($this->job)('mail_1', $run);

    $count = app(Automations::class)->cancelRunsOnMissingNodes(
        $this->automation,
        ['t', 'mail_1'],
        'the step was removed',
    );

    expect($count)->toBe(0)
        ->and($job->fresh()->status)->toBe(AutomationScheduledJob::STATUS_PENDING)
        ->and($run->fresh()->status)->toBe(AutomationRun::STATUS_WAITING);
});

it('cancels a call that has no run and reports it', function (): void {
    $job = ($this->job)('delay_2', null);

    $count = app(Automations::class)->cancelRunsOnMissingNodes($this->automation, ['t'], 'gone');

    expect($count)->toBe(1)
        ->and($job->fresh()->status)->toBe(AutomationScheduledJob::STATUS_CANCELLED);
});

it('does not touch a run that has already finished', function (): void {
    $run = ($this->run)(AutomationRun::STATUS_SUCCESS);
    ($this->job)('delay_2', $run);

    app(Automations::class)->cancelRunsOnMissingNodes($this->automation, ['t'], 'gone');

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_SUCCESS)
        ->and($run->fresh()->error_message)->toBeNull();
});

it('leaves calls that already fired or were cancelled out of the count', function (): void {
    $run = ($this->run)(AutomationRun::STATUS_RUNNING);
    $dispatched = ($this->job)('delay_2', $run, AutomationScheduledJob::STATUS_DISPATCHED);

    $count = app(Automations::class)->cancelRunsOnMissingNodes($this->automation, ['t'], 'gone');

    expect($count)->toBe(0)
        ->and($dispatched->fresh()->status)->toBe(AutomationScheduledJob::STATUS_DISPATCHED)
        ->and($run->fresh()->status)->toBe(AutomationRun::STATUS_RUNNING);
});

it('does not touch another automation', function (): void {
    $other = Automation::create([
        'name' => 'Andere',
        'handle' => 'andere_'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    $otherRun = ($this->run)(AutomationRun::STATUS_WAITING, $other);
    $otherJob = ($this->job)('delay_2', $otherRun, AutomationScheduledJob::STATUS_PENDING, $other);

    $count = app(Automations::class)->cancelRunsOnMissingNodes($this->automation, ['t'], 'gone');

    expect($count)->toBe(0)
        ->and($otherJob->fresh()->status)->toBe(AutomationScheduledJob::STATUS_PENDING)
        ->and($otherRun->fresh()->status)->toBe(AutomationRun::STATUS_WAITING);
});
