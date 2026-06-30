<?php

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Queue;

function makeEntrySavedAutomation(bool $enabled = true, ?string $collection = null): Automation
{
    $automation = Automation::create(['name' => 'On save', 'handle' => 'on-save', 'enabled' => $enabled]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => 'entry_saved',
        'config' => $collection ? ['collection' => $collection] : [],
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

beforeEach(function () {
    Queue::fake();
});

it('dispatches a run when an entry_saved event matches', function () {
    $automation = makeEntrySavedAutomation(collection: 'blog');

    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '42', 'collection' => 'blog']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch when the collection scope does not match', function () {
    $automation = makeEntrySavedAutomation(collection: 'blog');

    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '42', 'collection' => 'pages']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

it('ignores disabled automations', function () {
    $automation = makeEntrySavedAutomation(enabled: false);

    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '1', 'collection' => 'blog']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});
