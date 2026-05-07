<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class LeadTagAddedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'leadhub.lead_tag_added';
    }

    public static function label(): string
    {
        return 'Lead Tag Added';
    }

    public static function description(): ?string
    {
        return 'Triggered when a tag is added to a LeadHub lead.';
    }

    public static function group(): string
    {
        return 'LeadHub';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'tag',
                'label' => 'Tag',
                'type' => 'select',
                'options_source' => 'leadhub.tags',
                'required' => false,
                'help' => 'Leave empty to match any tag.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'lead' => 'array',
            'tag' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $tag = $config['tag'] ?? null;
        if (! $tag) {
            return true;
        }

        return ($this->payload($event)['tag'] ?? null) === $tag;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $payload = $this->payload($event);

        return AutomationContext::make([
            'lead' => $payload['lead'] ?? [],
            'tag' => $payload['tag'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(object|array $event): array
    {
        if (is_array($event)) {
            return $event;
        }

        return [
            'lead' => $event->lead ?? [],
            'tag' => $event->tag ?? null,
        ];
    }
}
