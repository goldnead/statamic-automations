<?php

use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Events\AutomationRunFinished;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

function finishedEventRun(array $attributes = []): AutomationRun
{
    $automation = Automation::create(['name' => 'Welcome', 'handle' => 'welcome', 'enabled' => true]);

    return AutomationRun::create(array_merge([
        'automation_id' => $automation->id,
        'status' => AutomationRun::STATUS_RUNNING,
        'context' => ['subscriber' => ['email' => 'Anna@Example.com']],
    ], $attributes));
}

it('fires once when a run completes and exposes handle and email', function () {
    Event::fake([AutomationRunFinished::class]);

    $run = finishedEventRun();

    app(RunLogger::class)->finishRun($run, AutomationRun::STATUS_SUCCESS);

    Event::assertDispatchedTimes(AutomationRunFinished::class, 1);

    Event::assertDispatched(AutomationRunFinished::class, function (AutomationRunFinished $event) use ($run) {
        expect($event->payload())->toMatchArray([
            'run_id' => $run->id,
            'automation' => 'welcome',
            'status' => AutomationRun::STATUS_SUCCESS,
            'email' => 'anna@example.com',
        ]);

        return true;
    });
});

it('does not fire when a run fails', function () {
    Mail::fake();
    Event::fake([AutomationRunFinished::class]);

    $run = finishedEventRun();

    app(RunLogger::class)->finishRun($run, AutomationRun::STATUS_FAILED, 'boom');

    Event::assertNotDispatched(AutomationRunFinished::class);
});

it('does not fire for a test run', function () {
    Event::fake([AutomationRunFinished::class]);

    $run = finishedEventRun(['is_test' => true]);

    app(RunLogger::class)->finishRun($run, AutomationRun::STATUS_SUCCESS);

    Event::assertNotDispatched(AutomationRunFinished::class);
});

it('keeps the run finished when a listener throws', function () {
    Event::listen(AutomationRunFinished::class, function () {
        throw new RuntimeException('listener broke');
    });

    $run = finishedEventRun();

    app(RunLogger::class)->finishRun($run, AutomationRun::STATUS_SUCCESS);

    $stored = AutomationRun::findOrFail($run->id);

    expect($stored->status)->toBe(AutomationRun::STATUS_SUCCESS)
        ->and($stored->finished_at)->not->toBeNull();
});
