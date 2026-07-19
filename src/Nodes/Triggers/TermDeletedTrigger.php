<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Support\ExtractsStatamicTerm;

class TermDeletedTrigger implements AutomationTrigger
{
    use ExtractsStatamicTerm;

    public static function handle(): string
    {
        return 'term_deleted';
    }

    public static function label(): string
    {
        return 'Term Deleted';
    }

    public static function description(): ?string
    {
        return 'Triggered when a taxonomy term is deleted.';
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
                'handle' => 'taxonomy',
                'label' => 'Taxonomy',
                'type' => 'select',
                'options_source' => 'statamic.taxonomies',
                'required' => false,
                'help' => 'Leave empty to match any taxonomy.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'term' => [
                'id' => 'string', 'title' => 'string', 'slug' => 'string', 'taxonomy' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->termMatchesScope($this->extractTerm($event), $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['term' => $this->extractTerm($event)]);
    }
}
