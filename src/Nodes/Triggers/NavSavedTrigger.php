<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Fires on Statamic's NavSaved event. No dedicated `statamic.navs`
 * options_source exists yet in NodesController::options() (Task 2.1), so
 * this trigger ships without a filter field for now — it fires for any
 * navigation save. Add a handle/taxonomy-style filter once that source
 * exists.
 */
class NavSavedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'nav_saved';
    }

    public static function label(): string
    {
        return 'Navigation Saved';
    }

    public static function description(): ?string
    {
        return 'Triggered when a navigation is saved.';
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
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'nav' => ['handle' => 'string', 'title' => 'string'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['nav' => $this->extractNav($event)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractNav(object|array $event): array
    {
        if (is_array($event)) {
            return $event['nav'] ?? [];
        }

        $nav = $event->nav ?? null;
        if (! is_object($nav)) {
            return [];
        }

        return [
            'handle' => method_exists($nav, 'handle') ? $nav->handle() : null,
            'title' => method_exists($nav, 'title') ? $nav->title() : null,
        ];
    }
}
