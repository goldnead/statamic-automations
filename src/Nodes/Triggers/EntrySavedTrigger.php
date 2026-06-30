<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Support\ExtractsStatamicEntry;

class EntrySavedTrigger implements AutomationTrigger
{
    use ExtractsStatamicEntry;

    public static function handle(): string
    {
        return 'entry_saved';
    }

    public static function label(): string
    {
        return 'Entry Saved';
    }

    public static function description(): ?string
    {
        return 'Triggered every time an entry is saved (created or updated).';
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
                'id' => 'string', 'title' => 'string', 'slug' => 'string',
                'collection' => 'string', 'site' => 'string', 'url' => 'string', 'data' => 'array',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->entryMatchesScope($this->extractEntry($event), $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $entry = $this->extractEntry($event);

        return AutomationContext::make([
            'entry' => $entry,
            'site' => ['handle' => $entry['site'] ?? 'default'],
        ]);
    }
}
