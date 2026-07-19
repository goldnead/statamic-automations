<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class GlobalSetSavedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'global_set_saved';
    }

    public static function label(): string
    {
        return 'Global Set Saved';
    }

    public static function description(): ?string
    {
        return 'Triggered when a global set is saved.';
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
                'handle' => 'global_set',
                'label' => 'Global Set',
                'type' => 'select',
                'options_source' => 'statamic.globals',
                'required' => false,
                'help' => 'Leave empty to match any global set.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'global_set' => ['id' => 'string', 'handle' => 'string', 'title' => 'string'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $expected = $config['global_set'] ?? null;
        if (empty($expected)) {
            return true;
        }

        return ($this->extractGlobalSet($event)['handle'] ?? null) === $expected;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['global_set' => $this->extractGlobalSet($event)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractGlobalSet(object|array $event): array
    {
        if (is_array($event)) {
            return $event['global_set'] ?? [];
        }

        $globalSet = $event->globals ?? null;
        if (! is_object($globalSet)) {
            return [];
        }

        return [
            'id' => method_exists($globalSet, 'id') ? $globalSet->id() : null,
            'handle' => method_exists($globalSet, 'handle') ? $globalSet->handle() : null,
            'title' => method_exists($globalSet, 'title') ? $globalSet->title() : null,
        ];
    }
}
