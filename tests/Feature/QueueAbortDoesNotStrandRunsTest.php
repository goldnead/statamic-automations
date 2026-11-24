<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\ResumeDelayedRun;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

function strandAutomation(): Automation
{
    $automation = Automation::create(['name' => 'Strand', 'handle' => 'strand', 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'wait', 'type' => 'delay',
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'a2', 'type' => 'add_log_entry',
        'config' => ['message' => 'after'],
    ]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'wait']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'wait', 'to_node_key' => 'a2']);

    return $automation;
}

function strandRun(Automation $automation): AutomationRun
{
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make([]);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    return $runner->execute($run, $ctx);
}

it('puts a claimed scheduled job back to pending when the dispatch throws', function () {
    $run = strandRun(strandAutomation());
    Carbon::setTestNow(now()->addDays(2));

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('redis down'));
    app()->instance(Dispatcher::class, $bus);

    $this->artisan('automations:run-due')->assertExitCode(1);

    $job = AutomationScheduledJob::where('automation_run_id', $run->id)->first();
    expect($job->status)->toBe(AutomationScheduledJob::STATUS_PENDING);
});

it('picks the job up again on the next tick once the queue is back', function () {
    $run = strandRun(strandAutomation());
    Carbon::setTestNow(now()->addDays(2));

    $real = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis down'));
    app()->instance(Dispatcher::class, $bus);
    $this->artisan('automations:run-due')->assertExitCode(1);

    app()->instance(Dispatcher::class, $real);

    $this->artisan('automations:run-due')->assertExitCode(0);

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_SUCCESS);
});

it('closes a running run as failed when the queue aborts RunAutomation', function () {
    $automation = strandAutomation();
    $run = AutomationRun::create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'status' => AutomationRun::STATUS_RUNNING,
        'started_at' => now(),
        'context' => [],
    ]);

    (new RunAutomation($run->id))->failed(new RuntimeException('Job has been attempted too many times'));

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(AutomationRun::STATUS_FAILED);
    expect($fresh->error_message)->toContain('attempted too many times');
    expect($fresh->finished_at)->not->toBeNull();
});

it('leaves a finished run alone when failed() fires late', function () {
    $automation = strandAutomation();
    $run = AutomationRun::create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'status' => AutomationRun::STATUS_SUCCESS,
        'finished_at' => now(),
        'context' => [],
    ]);

    (new RunAutomation($run->id))->failed(new RuntimeException('late'));

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_SUCCESS);
});

it('closes the run as failed when the queue aborts ResumeDelayedRun', function () {
    $run = strandRun(strandAutomation());
    $job = AutomationScheduledJob::where('automation_run_id', $run->id)->first();
    $run->forceFill(['status' => AutomationRun::STATUS_RUNNING])->save();
    $job->forceFill(['status' => AutomationScheduledJob::STATUS_DISPATCHED])->save();

    (new ResumeDelayedRun($job->id))->failed(new RuntimeException('worker killed'));

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_FAILED);
});
