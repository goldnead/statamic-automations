<?php

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\DispatchMode;

/**
 * What sync mode actually guarantees — and what it does not.
 *
 * The specification this was built from claimed that a synchronous run makes a
 * failure surface in the request. It does not. WorkflowRunner never throws: a
 * missing automation, a validation error and a missing trigger node are each
 * recorded on the run as `failed` with a message, and the runner returns
 * normally. That is deliberate and predates this switch — a queued run has
 * nobody to throw to.
 *
 * So the switch does not change error handling at all. What it changes is
 * *when* the run happens, and that is the whole point: with `sync` the run has
 * reached a terminal state by the time the dispatching request continues, so a
 * mail the automation sends is gone before the page finishes rendering. With
 * `async` the run has not started yet.
 *
 * These tests run without `Queue::fake()`, unlike DispatchModeTest: a faked
 * queue records jobs instead of running them, so a sync run would never happen
 * and the assertion below would pass for the wrong reason.
 */
function makeRunnableAutomation(string $mode, string $secondNodeType): Automation
{
    $automation = Automation::create([
        'name' => 'Rule',
        'handle' => 'rule-'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => 'entry_saved',
        'config' => [DispatchMode::CONFIG_KEY => $mode],
    ]);

    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'second',
        'type' => $secondNodeType,
        'config' => $secondNodeType === 'add_log_entry' ? ['message' => 'saved {{ entry.id }}'] : [],
    ]);

    AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 't',
        'to_node_key' => 'second',
    ]);

    return $automation;
}

function fireRunnableEntrySaved(): void
{
    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '42', 'collection' => 'blog']]);
}

it('has finished the run by the time the caller continues, in sync mode', function () {
    makeRunnableAutomation('sync', 'add_log_entry');

    fireRunnableEntrySaved();

    $run = AutomationRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('records a failure on the run rather than raising it to the caller, even in sync mode', function () {
    // The specification's claim, corrected. WorkflowRunner records; it does not
    // throw. If that ever changes, this test is where it shows up — and the
    // warning on the field has to change with it.
    makeRunnableAutomation('sync', 'a_node_type_that_is_not_registered');

    fireRunnableEntrySaved();

    $run = AutomationRun::query()->latest('id')->first();

    expect($run->status)->toBe(AutomationRun::STATUS_FAILED)
        ->and($run->error_message)->not->toBeNull();
});
