<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class EntryPublishedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'entry_published';
    }

    public static function label(): string
    {
        return 'Entry Published';
    }

    public static function description(): ?string
    {
        return 'Triggered when an entry is published.';
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
                'help' => 'Leave empty to match any collection.',
            ],
            [
                'handle' => 'site',
                'label' => 'Site',
                'type' => 'select',
                'options_source' => 'statamic.sites',
                'required' => false,
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'entry' => [
                'id' => 'string',
                'title' => 'string',
                'slug' => 'string',
                'collection' => 'string',
                'site' => 'string',
                'url' => 'string',
                'data' => 'array',
            ],
            'site' => ['handle' => 'string'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $expectedCollection = $config['collection'] ?? null;
        $expectedSite = $config['site'] ?? null;

        $entry = $this->extractEntry($event);

        if ($expectedCollection && ($entry['collection'] ?? null) !== $expectedCollection) {
            return false;
        }

        if ($expectedSite && ($entry['site'] ?? null) !== $expectedSite) {
            return false;
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $entry = $this->extractEntry($event);

        $data = [
            'entry' => $entry,
            'site' => ['handle' => $entry['site'] ?? 'default'],
        ];

        return AutomationContext::make($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractEntry(object|array $event): array
    {
        if (is_array($event)) {
            return $event['entry'] ?? [];
        }

        $entry = $event->entry ?? null;
        if (! is_object($entry)) {
            return [];
        }

        return [
            'id' => method_exists($entry, 'id') ? $entry->id() : null,
            'title' => method_exists($entry, 'get') ? $entry->get('title') : null,
            'slug' => method_exists($entry, 'slug') ? $entry->slug() : null,
            'collection' => method_exists($entry, 'collectionHandle')
                ? $entry->collectionHandle()
                : (method_exists($entry, 'collection') && is_object($entry->collection())
                    ? $entry->collection()->handle()
                    : null),
            'site' => method_exists($entry, 'locale') ? $entry->locale() : null,
            'url' => method_exists($entry, 'url') ? $entry->url() : null,
            'data' => method_exists($entry, 'data')
                ? (is_object($entry->data()) && method_exists($entry->data(), 'all')
                    ? $entry->data()->all()
                    : (array) $entry->data())
                : [],
        ];
    }
}
