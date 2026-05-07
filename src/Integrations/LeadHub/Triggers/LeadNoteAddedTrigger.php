<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class LeadNoteAddedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'leadhub.lead_note_added';
    }

    public static function label(): string
    {
        return 'Lead Note Added';
    }

    public static function description(): ?string
    {
        return 'Triggered when a note is added to a LeadHub lead.';
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
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'lead' => 'array',
            'note' => [
                'body' => 'string',
                'created_by' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $payload = is_array($event) ? $event : [
            'lead' => $event->lead ?? [],
            'note' => $event->note ?? [],
        ];

        return AutomationContext::make([
            'lead' => $payload['lead'] ?? [],
            'note' => $payload['note'] ?? [],
        ]);
    }
}
