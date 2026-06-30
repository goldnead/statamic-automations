<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;
use Statamic\Facades\Entry;

/**
 * Creates a new Statamic entry in the configured collection.
 *
 * Side effects are gated behind the `persist_statamic_changes` test-mode
 * flag so test runs never write content.
 */
class CreateEntryAction implements AutomationAction
{
    use NormalizesKeyValue;

    public static function handle(): string
    {
        return 'create_entry';
    }

    public static function label(): string
    {
        return 'Create Entry';
    }

    public static function description(): ?string
    {
        return 'Creates a new entry in a Statamic collection from token-resolved data.';
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
                'required' => true,
            ],
            [
                'handle' => 'site',
                'label' => 'Site',
                'type' => 'select',
                'options_source' => 'statamic.sites',
                'required' => false,
            ],
            [
                'handle' => 'slug',
                'label' => 'Slug',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional. Tokens allowed, e.g. {{ form.email }}.',
            ],
            [
                'handle' => 'published',
                'label' => 'Published',
                'type' => 'toggle',
                'default' => false,
            ],
            [
                'handle' => 'data',
                'label' => 'Field data',
                'type' => 'key_value',
                'required' => false,
                'help' => 'Field handle → value. Values may contain tokens.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $collection = $config['collection'] ?? null;
        if (empty($collection)) {
            return ActionResult::failed('A collection is required.');
        }

        $data = $this->normalizeKeyValue($config['data'] ?? []);
        $site = $config['site'] ?? null;
        $slug = $config['slug'] ?? null;
        $published = (bool) ($config['published'] ?? false);

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => compact('collection', 'site', 'slug', 'published', 'data'),
                'note' => 'Test mode — entry not created.',
            ]);
        }

        $entry = Entry::make()->collection($collection)->data($data);

        if ($site) {
            $entry->locale($site);
        }
        if (! empty($slug)) {
            $entry->slug($slug);
        }
        $entry->published($published);
        $entry->save();

        return ActionResult::success([
            'entry' => [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'collection' => $collection,
                'url' => method_exists($entry, 'url') ? $entry->url() : null,
            ],
        ]);
    }
}
