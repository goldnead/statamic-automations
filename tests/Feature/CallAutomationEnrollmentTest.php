<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\RestartPolicy;

/**
 * "Call Automation" enters the sub-run like a trigger: the enrollment gate decides,
 * and the sub-run carries the person's key. Before this, the sub-run had no key,
 * so series-exit, re-entry and wait_for_automation never applied to it.
 */
function makeEnrollableChild(array $triggerConfig = []): Automation
{
    $child = Automation::create([
        'name' => 'Child '.bin2hex(random_bytes(3)),
        'handle' => 'child_'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    AutomationNode::create([
        'automation_id' => $child->id,
        'node_key' => 't',
        'type' => 'manual',
        'config' => $triggerConfig,
    ]);
    AutomationNode::create([
        'automation_id' => $child->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => 'child ran'],
    ]);
    AutomationEdge::create([
        'automation_id' => $child->id,
        'from_node_key' => 't',
        'to_node_key' => 'log',
    ]);

    return $child->fresh(['nodes', 'edges']);
}

function callChild(Automation $child, array $data): ActionResult
{
    return app(CallAutomationAction::class)->execute(
        AutomationContext::make($data),
        ['automation' => $child->handle, 'wait' => true],
    );
}

it('gives the sub-run the person key of the target trigger', function (): void {
    $child = makeEnrollableChild([RestartPolicy::SUBJECT_CONFIG_KEY => 'subscriber.email']);

    $result = callChild($child, ['subscriber' => ['email' => 'Jane@Example.com']]);

    expect($result->output)->toMatchArray(['enrolled' => true])
        ->and(AutomationRun::where('automation_uuid', $child->uuid)->value('subject_key'))
        ->toBe('jane@example.com');
});

it('does not start the sub-run for a person who left the series', function (): void {
    $child = makeEnrollableChild([RestartPolicy::SUBJECT_CONFIG_KEY => 'subscriber.email']);
    app(SequenceOptOut::class)->add((string) $child->uuid, 'jane@example.com');

    $result = callChild($child, ['subscriber' => ['email' => 'jane@example.com']]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output)->toBe(['enrolled' => false, 'reason' => 'sequence_opt_out', 'queued' => false])
        ->and(AutomationRun::where('automation_uuid', $child->uuid)->count())->toBe(0);
});

it('applies the ignore re-entry rule to the sub-run', function (): void {
    $child = makeEnrollableChild([
        RestartPolicy::CONFIG_KEY => 'ignore',
        RestartPolicy::SUBJECT_CONFIG_KEY => 'subscriber.email',
    ]);
    $data = ['subscriber' => ['email' => 'jane@example.com']];

    $first = callChild($child, $data);
    $second = callChild($child, $data);

    expect($first->output['enrolled'])->toBeTrue()
        ->and($second->output)->toBe(['enrolled' => false, 'reason' => 'already_enrolled', 'queued' => false])
        ->and(AutomationRun::where('automation_uuid', $child->uuid)->count())->toBe(1);
});

it('runs the sub-run as before under the default policy', function (): void {
    $child = makeEnrollableChild();
    $data = ['subscriber' => ['email' => 'jane@example.com']];

    $first = callChild($child, $data);
    $second = callChild($child, $data);

    expect($first->output)->toHaveKeys(['enrolled', 'child_run_id', 'child_status'])
        ->and($first->output['enrolled'])->toBeTrue()
        ->and($second->output['enrolled'])->toBeTrue()
        ->and(AutomationRun::where('automation_uuid', $child->uuid)->count())->toBe(2);
});
