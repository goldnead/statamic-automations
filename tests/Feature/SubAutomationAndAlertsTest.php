<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Engine\FailureAlerter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode;
use Illuminate\Support\Facades\Log;

function makeCallableAutomation(): Automation
{
    $automation = Automation::create(['name' => 'Child', 'handle' => 'child', 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'log', 'type' => 'add_log_entry',
        'config' => ['message' => 'child ran'],
    ]);
    AutomationEdge::create([
        'automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'log',
    ]);

    return $automation;
}

it('call_automation runs a sub-automation and waits', function () {
    $child = makeCallableAutomation();
    $action = app(CallAutomationAction::class);

    $result = $action->execute(AutomationContext::make([]), ['automation' => 'child', 'wait' => true]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toHaveKey('child_run_id');
    expect(AutomationRun::where('automation_id', $child->id)->exists())->toBeTrue();
});

it('call_automation fails on a missing target', function () {
    $result = app(CallAutomationAction::class)
        ->execute(AutomationContext::make([]), ['automation' => 'nope']);

    expect($result->isFailed())->toBeTrue();
});

it('call_automation guards against deep recursion', function () {
    makeCallableAutomation();
    $ctx = AutomationContext::make(['_call_depth' => 3]);

    $result = app(CallAutomationAction::class)->execute($ctx, ['automation' => 'child']);

    expect($result->isFailed())->toBeTrue();
});

it('wait_until continues immediately when conditions match', function () {
    $ctx = AutomationContext::make(['lead' => ['status' => 'qualified']]);
    $config = [
        'mode' => 'all',
        'conditions' => [['field' => 'lead.status', 'operator' => 'equals', 'value' => 'qualified']],
    ];

    $result = WaitUntilNode::evaluate($ctx, $config, app(ConditionEvaluator::class));

    expect($result->isSuccess())->toBeTrue();
    expect($result->isWaiting())->toBeFalse();
});

it('wait_until pauses when conditions are not met', function () {
    $ctx = AutomationContext::make(['lead' => ['status' => 'new']]);
    $config = [
        'mode' => 'all',
        'conditions' => [['field' => 'lead.status', 'operator' => 'equals', 'value' => 'qualified']],
        'recheck_minutes' => 10,
    ];

    $result = WaitUntilNode::evaluate($ctx, $config, app(ConditionEvaluator::class));

    expect($result->isWaiting())->toBeTrue();
    expect($result->waitUntil['seconds'])->toBe(600);
});

/**
 * A failed run of a real automation, the way the engine writes it.
 */
function makeFailedRun(string $handle): AutomationRun
{
    $automation = Automation::create(['name' => ucfirst($handle), 'handle' => $handle, 'enabled' => true]);

    return AutomationRun::create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'trigger_type' => 'manual',
        'status' => 'failed',
        'context' => [],
        'is_test' => false,
    ]);
}

it('failure alerter logs and throttles', function () {
    config()->set('automations.alerts', ['enabled' => true, 'channels' => ['log'], 'throttle_minutes' => 15]);

    $run = makeFailedRun('flapping');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->once();

    $alerter = app(FailureAlerter::class);
    $alerter->notify($run, 'boom');
    // Second call within the throttle window must not log again.
    $alerter->notify($run, 'boom again');
});

it('failure alerter throttles per automation, not globally', function () {
    config()->set('automations.alerts', ['enabled' => true, 'channels' => ['log'], 'throttle_minutes' => 15]);

    $first = makeFailedRun('first');
    $second = makeFailedRun('second');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->twice();

    $alerter = app(FailureAlerter::class);
    $alerter->notify($first, 'boom');
    $alerter->notify($second, 'boom');
});

/**
 * A run whose automation has been deleted. The foreign key is
 * `ON DELETE SET NULL`, so this — and not a dangling id — is the orphan the
 * database actually produces. The test used to fabricate `automation_id = 999`,
 * which no MySQL install would accept and which hid two things: that the
 * alerter is reachable with a null automation at all, and that it keyed its
 * throttle on that null.
 */
it('failure alerter still alerts for a run whose automation was deleted', function () {
    config()->set('automations.alerts', ['enabled' => true, 'channels' => ['log'], 'throttle_minutes' => 15]);

    $run = makeFailedRun('deleted-later');
    Automation::find($run->automation_id)->delete();
    $run->refresh();

    expect($run->automation_id)->toBeNull()
        ->and($run->automation_uuid)->not->toBeNull();

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->once();

    app(FailureAlerter::class)->notify($run, 'boom');
});

it('two orphaned runs from different automations do not throttle each other', function () {
    // Both have automation_id = null after the delete. Keying the throttle on
    // that null gave every orphaned run in the installation the same cache key,
    // so the first failure silenced all the others for the whole window.
    config()->set('automations.alerts', ['enabled' => true, 'channels' => ['log'], 'throttle_minutes' => 15]);

    $first = makeFailedRun('gone-a');
    $second = makeFailedRun('gone-b');
    Automation::find($first->automation_id)->delete();
    Automation::find($second->automation_id)->delete();
    $first->refresh();
    $second->refresh();

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->twice();

    $alerter = app(FailureAlerter::class);
    $alerter->notify($first, 'boom');
    $alerter->notify($second, 'boom');
});
