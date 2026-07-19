<?php

/**
 * Task 3 — broader Statamic event coverage. Locks in that each new trigger
 * handle is wired through TriggerDispatcher end-to-end: an enabled
 * automation whose start node is that trigger runs exactly once when the
 * matching event fires, respects its filter (when configured), and does
 * NOT run for a non-matching event/filter combination.
 *
 * Mirrors the array-event dispatch pattern from EventTriggersTest.php
 * (dispatching a plain array payload through TriggerDispatcher rather than
 * constructing real Statamic domain objects) — the trigger classes are
 * explicitly tolerant of array events for this reason.
 */

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $config
 */
function makeTriggerAutomation(string $handle, string $type, array $config = []): Automation
{
    $automation = Automation::create(['name' => "On {$type}", 'handle' => "on-{$type}-{$handle}", 'enabled' => true]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => $type,
        'config' => $config,
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => 'fired'],
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

// --- entry_created ----------------------------------------------------------

it('dispatches a run when an entry_created event matches the configured collection', function () {
    $automation = makeTriggerAutomation('created', 'entry_created', ['collection' => 'blog']);

    app(TriggerDispatcher::class)->dispatch('entry_created', ['entry' => ['id' => '1', 'collection' => 'blog']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch entry_created for a different collection', function () {
    $automation = makeTriggerAutomation('created', 'entry_created', ['collection' => 'blog']);

    app(TriggerDispatcher::class)->dispatch('entry_created', ['entry' => ['id' => '1', 'collection' => 'pages']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

// --- entry_saving -------------------------------------------------------

it('dispatches a run when an entry_saving event matches', function () {
    $automation = makeTriggerAutomation('saving', 'entry_saving');

    app(TriggerDispatcher::class)->dispatch('entry_saving', ['entry' => ['id' => '1', 'collection' => 'blog']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

// --- term_saved ---------------------------------------------------------

it('dispatches a run when a term_saved event matches the configured taxonomy', function () {
    $automation = makeTriggerAutomation('saved', 'term_saved', ['taxonomy' => 'tags']);

    app(TriggerDispatcher::class)->dispatch('term_saved', ['term' => ['id' => '1', 'taxonomy' => 'tags']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch term_saved for a different taxonomy', function () {
    $automation = makeTriggerAutomation('saved', 'term_saved', ['taxonomy' => 'tags']);

    app(TriggerDispatcher::class)->dispatch('term_saved', ['term' => ['id' => '1', 'taxonomy' => 'categories']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

it('dispatches term_saved for any taxonomy when unfiltered', function () {
    $automation = makeTriggerAutomation('saved', 'term_saved');

    app(TriggerDispatcher::class)->dispatch('term_saved', ['term' => ['id' => '1', 'taxonomy' => 'categories']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

// --- term_deleted ---------------------------------------------------------

it('dispatches a run when a term_deleted event matches the configured taxonomy', function () {
    $automation = makeTriggerAutomation('deleted', 'term_deleted', ['taxonomy' => 'tags']);

    app(TriggerDispatcher::class)->dispatch('term_deleted', ['term' => ['id' => '1', 'taxonomy' => 'tags']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch term_deleted for a different taxonomy', function () {
    $automation = makeTriggerAutomation('deleted', 'term_deleted', ['taxonomy' => 'tags']);

    app(TriggerDispatcher::class)->dispatch('term_deleted', ['term' => ['id' => '1', 'taxonomy' => 'categories']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

// --- user_saved -----------------------------------------------------------

it('dispatches a run when a user_saved event matches the configured role', function () {
    $automation = makeTriggerAutomation('saved', 'user_saved', ['role' => 'editor']);

    app(TriggerDispatcher::class)->dispatch('user_saved', ['user' => ['id' => '1', 'roles' => ['editor']]]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch user_saved for a different role', function () {
    $automation = makeTriggerAutomation('saved', 'user_saved', ['role' => 'editor']);

    app(TriggerDispatcher::class)->dispatch('user_saved', ['user' => ['id' => '1', 'roles' => ['author']]]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

// --- user_deleted -----------------------------------------------------------

it('dispatches a run when a user_deleted event matches any role (unfiltered)', function () {
    $automation = makeTriggerAutomation('deleted', 'user_deleted');

    app(TriggerDispatcher::class)->dispatch('user_deleted', ['user' => ['id' => '1', 'roles' => ['author']]]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

// --- asset_uploaded ---------------------------------------------------------

it('dispatches a run when an asset_uploaded event matches the configured container', function () {
    $automation = makeTriggerAutomation('uploaded', 'asset_uploaded', ['container' => 'assets']);

    app(TriggerDispatcher::class)->dispatch('asset_uploaded', ['asset' => ['id' => '1', 'container' => 'assets']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch asset_uploaded for a different container', function () {
    $automation = makeTriggerAutomation('uploaded', 'asset_uploaded', ['container' => 'assets']);

    app(TriggerDispatcher::class)->dispatch('asset_uploaded', ['asset' => ['id' => '1', 'container' => 'documents']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

// --- asset_saved ---------------------------------------------------------

it('dispatches a run when an asset_saved event matches', function () {
    $automation = makeTriggerAutomation('saved', 'asset_saved');

    app(TriggerDispatcher::class)->dispatch('asset_saved', ['asset' => ['id' => '1', 'container' => 'assets']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

// --- asset_deleted ---------------------------------------------------------

it('dispatches a run when an asset_deleted event matches the configured container', function () {
    $automation = makeTriggerAutomation('deleted', 'asset_deleted', ['container' => 'assets']);

    app(TriggerDispatcher::class)->dispatch('asset_deleted', ['asset' => ['id' => '1', 'container' => 'assets']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch asset_deleted for a different container', function () {
    $automation = makeTriggerAutomation('deleted', 'asset_deleted', ['container' => 'assets']);

    app(TriggerDispatcher::class)->dispatch('asset_deleted', ['asset' => ['id' => '1', 'container' => 'documents']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

// --- global_set_saved ---------------------------------------------------------

it('dispatches a run when a global_set_saved event matches the configured global set', function () {
    $automation = makeTriggerAutomation('saved', 'global_set_saved', ['global_set' => 'seo']);

    app(TriggerDispatcher::class)->dispatch('global_set_saved', ['global_set' => ['handle' => 'seo']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch global_set_saved for a different global set', function () {
    $automation = makeTriggerAutomation('saved', 'global_set_saved', ['global_set' => 'seo']);

    app(TriggerDispatcher::class)->dispatch('global_set_saved', ['global_set' => ['handle' => 'social']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

// --- nav_saved ---------------------------------------------------------

it('dispatches a run when a nav_saved event fires (no filter)', function () {
    $automation = makeTriggerAutomation('saved', 'nav_saved');

    app(TriggerDispatcher::class)->dispatch('nav_saved', ['nav' => ['handle' => 'main']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

// --- disabled automations are ignored across all new trigger handles --------

it('ignores disabled automations for a new trigger handle', function () {
    $automation = Automation::create(['name' => 'Disabled', 'handle' => 'disabled-term-saved', 'enabled' => false]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => 'term_saved',
        'config' => [],
    ]);

    app(TriggerDispatcher::class)->dispatch('term_saved', ['term' => ['id' => '1', 'taxonomy' => 'tags']]);

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});
