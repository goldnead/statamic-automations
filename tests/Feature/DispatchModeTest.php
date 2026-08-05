<?php

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Illuminate\Support\Facades\Queue;

/**
 * Sync or async, per trigger.
 *
 * The first two cases are the ones that matter most: the default has not
 * changed and may not change. Every automation in every install runs through
 * the queue today, and a release that started running them inside the request
 * would tie page response times — and page failures — to automation runtime
 * without anybody asking for it.
 *
 * The shape of the fixture follows EventTriggersTest rather than being minimal:
 * a trigger alone is not an automation the dispatcher will run, so a test built
 * on one would pass its "nothing was queued" assertion for the wrong reason.
 */
function makeRuleAutomation(?string $mode): Automation
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
        'config' => $mode === null ? [] : [DispatchMode::CONFIG_KEY => $mode],
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

function fireEntrySaved(): void
{
    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '42', 'collection' => 'blog']]);
}

beforeEach(function () {
    Queue::fake();
});

it('still goes through the queue when nothing is set', function () {
    makeRuleAutomation(null);

    fireEntrySaved();

    Queue::assertPushed(RunAutomation::class, fn ($job) => $job->connection !== 'sync');
});

it('still goes through the queue on an explicit async', function () {
    makeRuleAutomation('async');

    fireEntrySaved();

    Queue::assertPushed(RunAutomation::class, fn ($job) => $job->connection !== 'sync');
});

it('runs on the sync connection in sync mode', function () {
    // Not `assertNothingPushed()`: dispatchSync() on a ShouldQueue job routes
    // it to the `sync` connection, which a faked queue still records. The
    // observable difference is the connection, and that is what decides
    // whether the run happens inside the caller's request or later.
    makeRuleAutomation('sync');

    fireEntrySaved();

    Queue::assertPushed(RunAutomation::class, fn ($job) => $job->connection === 'sync');
});

it('reads an unknown value as async rather than guessing', function () {
    makeRuleAutomation('vielleicht');

    fireEntrySaved();

    Queue::assertPushed(RunAutomation::class, fn ($job) => $job->connection !== 'sync');
});

it('offers the switch on every trigger without each trigger declaring it', function () {
    $schema = app(NodeRegistry::class)->describe('entry_saved')['schema'];

    expect(array_column($schema, 'handle'))->toContain(DispatchMode::CONFIG_KEY);
});
