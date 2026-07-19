<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Statamic\Facades\Entry;

/**
 * Deletes an existing Statamic entry, identified by id (token-resolved).
 *
 * Destructive: the side effect is gated behind `persist_statamic_changes`,
 * so a test run reports what it would delete without deleting anything.
 */
class DeleteEntryAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'delete_entry';
    }

    public static function label(): string
    {
        return 'Delete Entry';
    }

    public static function description(): ?string
    {
        return 'Permanently deletes an entry, identified by its id. Destructive.';
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
                'help' => 'Pick an entry, or use a token. This entry is deleted permanently.',
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
                'deleted' => 'boolean',
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
                'preview' => ['entry_id' => $id],
                'note' => 'Test mode — entry not deleted.',
            ]);
        }

        $entry = Entry::find($id);
        if ($entry === null) {
            return ActionResult::failed("Entry '{$id}' not found.");
        }

        $entry->delete();

        return ActionResult::success([
            'entry' => ['id' => (string) $id, 'deleted' => true],
        ]);
    }
}
