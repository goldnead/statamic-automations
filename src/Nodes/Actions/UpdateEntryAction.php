<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;
use Statamic\Facades\Entry;

/**
 * Updates an existing Statamic entry, identified by id (token-resolved).
 */
class UpdateEntryAction implements AutomationAction
{
    use NormalizesKeyValue;

    public static function handle(): string
    {
        return 'update_entry';
    }

    public static function label(): string
    {
        return 'Update Entry';
    }

    public static function description(): ?string
    {
        return 'Updates field data on an existing entry, identified by its id.';
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
                'help' => 'Optional. Scopes the entry picker below — has no effect on the update itself.',
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
            [
                'handle' => 'published',
                'label' => 'Set published state',
                'type' => 'select',
                'options' => [
                    ['value' => '', 'label' => 'Leave unchanged'],
                    ['value' => 'publish', 'label' => 'Publish'],
                    ['value' => 'unpublish', 'label' => 'Unpublish'],
                ],
                'default' => '',
            ],
            [
                'handle' => 'data',
                'label' => 'Field data',
                'type' => 'key_value',
                'required' => false,
                'tokenable' => true,
            ],
        ];
    }

    /**
     * Variables this action exposes downstream, e.g. {{ node.entry.id }}.
     * Mirrors the keys returned on the success path of execute().
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'entry' => [
                'id' => 'string',
                'slug' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $id = $config['entry_id'] ?? null;
        if (empty($id)) {
            return ActionResult::failed('An entry id is required.');
        }

        $data = $this->normalizeKeyValue($config['data'] ?? []);
        $publishedChange = $config['published'] ?? '';

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => ['entry_id' => $id, 'data' => $data, 'published' => $publishedChange],
                'note' => 'Test mode — entry not updated.',
            ]);
        }

        $entry = Entry::find($id);
        if ($entry === null) {
            return ActionResult::failed("Entry '{$id}' not found.");
        }

        foreach ($data as $key => $value) {
            $entry->set($key, $value);
        }

        if ($publishedChange === 'publish') {
            $entry->published(true);
        } elseif ($publishedChange === 'unpublish') {
            $entry->published(false);
        }

        $entry->save();

        return ActionResult::success([
            'entry' => ['id' => $entry->id(), 'slug' => $entry->slug()],
        ]);
    }
}
