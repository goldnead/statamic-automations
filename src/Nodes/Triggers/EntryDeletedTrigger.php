<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Support\ExtractsStatamicEntry;

class EntryDeletedTrigger implements AutomationTrigger
{
    use ExtractsStatamicEntry;

    public static function handle(): string
    {
        return 'entry_deleted';
    }

    public static function label(): string
    {
        return 'Entry Deleted';
    }

    public static function description(): ?string
    {
        return 'Triggered when an entry is deleted.';
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
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'entry' => [
                'id' => 'string', 'title' => 'string', 'slug' => 'string',
                'collection' => 'string', 'data' => 'array',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->entryMatchesScope($this->extractEntry($event), $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['entry' => $this->extractEntry($event)]);
    }
}
