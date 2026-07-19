<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Statamic\Facades\Entry;

/**
 * Publishes an existing Statamic entry, identified by id (token-resolved).
 *
 * Side effects are gated behind `persist_statamic_changes` so test runs
 * never write.
 */
class PublishEntryAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'publish_entry';
    }

    public static function label(): string
    {
        return 'Publish Entry';
    }

    public static function description(): ?string
    {
        return 'Sets an existing entry to published.';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'collection',
                'label' => 'Collection',
                'type' => 'select',
                'options_source' => 'statamic.collections',
                'required' => false,
                'help' => 'Optional. Scopes the entry picker below — no effect on the action itself.',
            ],
            [
                'handle' => 'entry_id',
                'label' => 'Entry',
                'type' => 'select',
                'options_source' => 'entries',
                'depends_on' => 'collection',
                'required' => true,
                'tokenable' => true,
                'help' => 'Pick an entry, or use a token, e.g. {{ entry.id }}.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'entry' => [
                'id' => 'string',
                'slug' => 'string',
                'published' => 'boolean',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $id = $config['entry_id'] ?? null;
        if (empty($id)) {
            return ActionResult::failed('An entry id is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => ['entry_id' => $id, 'published' => true],
                'note' => 'Test mode — entry not published.',
            ]);
        }

        $entry = Entry::find($id);
        if ($entry === null) {
            return ActionResult::failed("Entry '{$id}' not found.");
        }

        $entry->published(true);
        $entry->save();

        return ActionResult::success([
            'entry' => ['id' => $entry->id(), 'slug' => $entry->slug(), 'published' => true],
        ]);
    }
}
