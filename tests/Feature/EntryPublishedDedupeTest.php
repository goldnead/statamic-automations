<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Semantics under test (matching goldnead/statamic-webhook-manager):
 * - entry_saved fires on EVERY save, including publish-saves.
 * - entry_published fires ONLY when the saved entry is published.
 * - A single published save fires each trigger type exactly ONCE
 *   (no double-processing of entry_published).
 */
function makeEntryTriggerAutomation(string $triggerType): Automation
{
    $automation = Automation::create([
        'name' => "On {$triggerType}",
        'handle' => "on-{$triggerType}",
        'enabled' => true,
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => $triggerType,
        'config' => [],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => "{$triggerType} {{ entry.id }}"],
    ]);
    AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 't',
        'to_node_key' => 'log',
    ]);

    return $automation;
}

function fakeStatamicEntry(bool $published): object
{
    return new class($published)
    {
        public function __construct(protected bool $isPublished)
        {
        }

        public function published(): bool
        {
            return $this->isPublished;
        }

        public function id(): string
        {
            return 'entry-1';
        }

        public function root(): object
        {
            return $this;
        }

        public function get(string $key): ?string
        {
            return $key === 'title' ? 'Test entry' : null;
        }

        public function slug(): string
        {
            return 'test-entry';
        }

        public function collectionHandle(): string
        {
            return 'blog';
        }

        public function locale(): string
        {
            return 'default';
        }

        public function url(): string
        {
            return '/blog/test-entry';
        }

        public function data(): array
        {
            return ['title' => 'Test entry'];
        }
    };
}

function dispatchEntrySaved(bool $published): void
{
    // Dispatch the real event class the ServiceProvider listens on, so the
    // full listener wiring (including Statamic's own subscribers) runs.
    Event::dispatch(new \Statamic\Events\EntrySaved(fakeStatamicEntry($published)));
}

beforeEach(function () {
    Queue::fake();
});

it('fires entry_saved and entry_published exactly once each for a published save', function () {
    $saved = makeEntryTriggerAutomation('entry_saved');
    $published = makeEntryTriggerAutomation('entry_published');

    dispatchEntrySaved(published: true);

    expect(AutomationRun::where('automation_id', $saved->id)->count())->toBe(1)
        ->and(AutomationRun::where('automation_id', $published->id)->count())->toBe(1);
});

it('fires only entry_saved for a plain draft save', function () {
    $saved = makeEntryTriggerAutomation('entry_saved');
    $published = makeEntryTriggerAutomation('entry_published');

    dispatchEntrySaved(published: false);

    expect(AutomationRun::where('automation_id', $saved->id)->count())->toBe(1)
        ->and(AutomationRun::where('automation_id', $published->id)->count())->toBe(0);
});
