<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ThrottleNode;
use Illuminate\Support\Facades\Cache;

function makeChild(string $handle): Automation
{
    $automation = Automation::create(['name' => $handle, 'handle' => $handle, 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'log', 'type' => 'add_log_entry',
        'config' => ['message' => "{$handle} ran"],
    ]);
    AutomationEdge::create([
        'automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'log',
    ]);

    return $automation;
}

it('loop (legacy automation mode) runs the body once per item', function () {
    $child = makeChild('per-item');
    $result = app(LoopNode::class)->execute(
        AutomationContext::make([]),
        ['items' => ['a', 'b', 'c'], 'mode' => 'automation', 'automation' => 'per-item', 'item_key' => 'item']
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['iterations'])->toBe(3);
    expect(AutomationRun::where('automation_id', $child->id)->count())->toBe(3);
});

it('loop (legacy automation mode) respects the max_iterations cap', function () {
    makeChild('capped');
    $result = app(LoopNode::class)->execute(
        AutomationContext::make([]),
        ['items' => range(1, 50), 'mode' => 'automation', 'automation' => 'capped', 'max_iterations' => 5]
    );

    expect($result->output['iterations'])->toBe(5);
});

it('loop (legacy automation mode) fails on a missing body automation', function () {
    $result = app(LoopNode::class)->execute(
        AutomationContext::make([]),
        ['items' => ['a'], 'mode' => 'automation', 'automation' => 'does-not-exist']
    );

    expect($result->isFailed())->toBeTrue();
});

it('loop (inline mode, default) resolves items and routes to loop/done without an automation', function () {
    $viaLoop = app(LoopNode::class)->execute(
        AutomationContext::make([]),
        ['items' => ['a', 'b', 'c']]
    );

    expect($viaLoop->isSuccess())->toBeTrue();
    expect($viaLoop->outputHandle)->toBe('loop');
    expect($viaLoop->output['items'])->toBe(['a', 'b', 'c']);

    $viaDone = app(LoopNode::class)->execute(
        AutomationContext::make([]),
        ['items' => []]
    );

    expect($viaDone->isSuccess())->toBeTrue();
    expect($viaDone->outputHandle)->toBe('done');
});

it('parallel fans out to named branches and joins results', function () {
    makeChild('alpha');
    makeChild('beta');

    $result = app(ParallelNode::class)->execute(
        AutomationContext::make([]),
        ['branches' => ['a' => 'alpha', 'b' => 'beta']]
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['branches'])->toHaveKeys(['a', 'b']);
    expect($result->output['branches']['a']['status'])->toBe(AutomationRun::STATUS_SUCCESS);
});

it('parallel fails fast when a branch is missing and fail_fast is on', function () {
    $result = app(ParallelNode::class)->execute(
        AutomationContext::make([]),
        ['branches' => ['a' => 'nope'], 'fail_fast' => true]
    );

    expect($result->isFailed())->toBeTrue();
});

it('throttle stops a duplicate within the window', function () {
    Cache::flush();
    $node = new ThrottleNode();

    $first = $node->execute(AutomationContext::make([]), ['key' => 'order-7', 'window_minutes' => 60]);
    expect($first->isSuccess())->toBeTrue();

    $second = $node->execute(AutomationContext::make([]), ['key' => 'order-7', 'window_minutes' => 60]);
    expect($second->isStopped())->toBeTrue();
});

it('throttle never records in test mode', function () {
    Cache::flush();
    $node = new ThrottleNode();
    $ctx = AutomationContext::make([], testMode: true);

    expect($node->execute($ctx, ['key' => 'x'])->isSuccess())->toBeTrue();
    expect($node->execute($ctx, ['key' => 'x'])->isSuccess())->toBeTrue();
});
