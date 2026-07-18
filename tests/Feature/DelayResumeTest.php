<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * trigger(manual) → action1 → delay(1 day) → action2
 * action2 references a trigger-seeded token to prove context survival.
 */
function delayAutomation(): Automation
{
    $automation = Automation::create(['name' => 'Delayed', 'handle' => 'delayed', 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'a1', 'type' => 'add_log_entry',
        'config' => ['message' => 'first step'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'wait', 'type' => 'delay',
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'a2', 'type' => 'add_log_entry',
        'config' => ['message' => 'hello {{ subscriber.email }}'],
    ]);

    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'a1']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'a1', 'to_node_key' => 'wait']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'wait', 'to_node_key' => 'a2']);

    return $automation;
}

function runDelayed(Automation $automation, array $seed = []): AutomationRun
{
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make($seed);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    return $runner->execute($run, $ctx);
}

it('pauses the run at a delay node without running the downstream step', function () {
    $automation = delayAutomation();

    $run = runDelayed($automation, ['subscriber' => ['email' => 'anna@example.com']]);

    expect($run->status)->toBe(AutomationRun::STATUS_WAITING);
    expect($run->finished_at)->toBeNull();

    // Exactly one PENDING scheduled job parked on the delay node.
    $jobs = AutomationScheduledJob::where('automation_run_id', $run->id)->get();
    expect($jobs)->toHaveCount(1);
    expect($jobs->first()->status)->toBe(AutomationScheduledJob::STATUS_PENDING);
    expect($jobs->first()->node_key)->toBe('wait');

    // action2 has NOT run yet.
    expect($run->nodeRuns()->where('node_key', 'a2')->exists())->toBeFalse();
    // action1 already ran (it precedes the delay).
    expect($run->nodeRuns()->where('node_key', 'a1')->exists())->toBeTrue();
});

it('resumes the run after the delay elapses and reaches success', function () {
    $automation = delayAutomation();
    $run = runDelayed($automation, ['subscriber' => ['email' => 'anna@example.com']]);

    expect($run->status)->toBe(AutomationRun::STATUS_WAITING);

    // Travel past the due_at (delay was 1 day).
    Carbon::setTestNow(now()->addDays(2));

    $this->artisan('automations:run-due')->assertExitCode(0);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(AutomationRun::STATUS_SUCCESS);
    expect($fresh->nodeRuns()->where('node_key', 'a2')->exists())->toBeTrue();

    // Scheduled job consumed exactly once.
    expect(AutomationScheduledJob::find(
        AutomationScheduledJob::where('automation_run_id', $run->id)->first()->id
    )->status)->toBe(AutomationScheduledJob::STATUS_DISPATCHED);
});

it('preserves operational context across the pause', function () {
    $automation = delayAutomation();
    $run = runDelayed($automation, ['subscriber' => ['email' => 'anna@example.com']]);

    Carbon::setTestNow(now()->addDays(2));
    $this->artisan('automations:run-due')->assertExitCode(0);

    // action2's resolved message proves the seeded token survived the pause.
    $nodeRun = $run->fresh()->nodeRuns()->where('node_key', 'a2')->first();
    expect($nodeRun)->not->toBeNull();
    expect($nodeRun->output['message'] ?? null)->toBe('hello anna@example.com');
});

it('resumes twice through chained delays and completes', function () {
    $automation = Automation::create(['name' => 'Chained', 'handle' => 'chained', 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'd1', 'type' => 'delay',
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'mid', 'type' => 'add_log_entry',
        'config' => ['message' => 'between delays'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'd2', 'type' => 'delay',
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'done', 'type' => 'add_log_entry',
        'config' => ['message' => 'finished'],
    ]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'd1']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'd1', 'to_node_key' => 'mid']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'mid', 'to_node_key' => 'd2']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'd2', 'to_node_key' => 'done']);

    $run = runDelayed($automation);
    expect($run->status)->toBe(AutomationRun::STATUS_WAITING);

    // First resume: fires d1, runs 'mid', parks again on d2.
    Carbon::setTestNow(now()->addDays(2));
    $this->artisan('automations:run-due')->assertExitCode(0);

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_WAITING);
    expect($run->fresh()->nodeRuns()->where('node_key', 'mid')->exists())->toBeTrue();
    expect($run->fresh()->nodeRuns()->where('node_key', 'done')->exists())->toBeFalse();

    // Second resume: fires d2, runs 'done', completes.
    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('automations:run-due')->assertExitCode(0);

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_SUCCESS);
    expect($run->fresh()->nodeRuns()->where('node_key', 'done')->exists())->toBeTrue();
});

it('executes the downstream step only once when run-due is invoked twice', function () {
    $automation = delayAutomation();
    $run = runDelayed($automation, ['subscriber' => ['email' => 'anna@example.com']]);

    Carbon::setTestNow(now()->addDays(2));

    $this->artisan('automations:run-due')->assertExitCode(0);
    $this->artisan('automations:run-due')->assertExitCode(0);

    // action2 recorded exactly one node run — no double fire.
    expect($run->fresh()->nodeRuns()->where('node_key', 'a2')->count())->toBe(1);
});

it('does not dispatch scheduled jobs whose due_at is still in the future', function () {
    $automation = delayAutomation();
    $run = runDelayed($automation, ['subscriber' => ['email' => 'anna@example.com']]);

    // No time travel — the 1-day delay is still in the future.
    $this->artisan('automations:run-due')->assertExitCode(0);

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_WAITING);
    expect($run->fresh()->nodeRuns()->where('node_key', 'a2')->exists())->toBeFalse();
    expect(AutomationScheduledJob::where('automation_run_id', $run->id)->first()->status)
        ->toBe(AutomationScheduledJob::STATUS_PENDING);
});
