<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function entrySavedFor(string $handle): Automation
{
    $automation = Automation::create(['name' => $handle, 'handle' => $handle, 'enabled' => true]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => 'entry_saved',
        'config' => [],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => 'saved {{ entry.id }}'],
    ]);
    AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 't',
        'to_node_key' => 'log',
    ]);

    return $automation;
}

function flowWithMiddleNode(string $middleType, array $middleConfig, bool $middleDisabled = false): Automation
{
    $automation = Automation::create(['name' => 'Mid', 'handle' => 'mid-'.$middleType, 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'mid', 'type' => $middleType,
        'config' => $middleConfig, 'disabled' => $middleDisabled,
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'after', 'type' => 'add_log_entry',
        'config' => ['message' => 'continued'],
    ]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'mid']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'mid', 'to_node_key' => 'after']);

    return $automation;
}

it('leaves a warning in the log when a failing node is continued past', function () {
    Log::spy();
    Log::shouldReceive('channel')->andReturnSelf();

    // Same failing node as ReliabilityTest: a call_automation to a missing target.
    $automation = flowWithMiddleNode('call_automation', ['automation' => 'does-not-exist-xyz', '_on_error' => 'continue']);
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make([]);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    $final = $runner->execute($run, $ctx);

    // Status stays as it was (behaviour unchanged); the continuation is now visible in the log.
    expect($final->status)->toBe(AutomationRun::STATUS_SUCCESS);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []) => ($context['run_id'] ?? null) === $run->id
            && ($context['node_key'] ?? null) === 'mid'
    );
});

it('leaves a warning in the log when a disabled node stops the flow', function () {
    Log::spy();
    Log::shouldReceive('channel')->andReturnSelf();

    $automation = flowWithMiddleNode('add_log_entry', ['message' => 'mid'], middleDisabled: true);
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make([]);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    $final = $runner->execute($run, $ctx);

    expect($final->status)->toBe(AutomationRun::STATUS_STOPPED);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []) => ($context['run_id'] ?? null) === $run->id
            && ($context['node_key'] ?? null) === 'mid'
    );
});
