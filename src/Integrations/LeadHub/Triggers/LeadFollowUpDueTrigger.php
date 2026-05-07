<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class LeadFollowUpDueTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'leadhub.lead_follow_up_due';
    }

    public static function label(): string
    {
        return 'Lead Follow-up Due';
    }

    public static function description(): ?string
    {
        return 'Triggered when a LeadHub follow-up becomes due.';
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
                'handle' => 'window',
                'label' => 'Window',
                'type' => 'select',
                'options' => [
                    ['value' => 'due_today', 'label' => 'Due today'],
                    ['value' => 'overdue', 'label' => 'Overdue'],
                    ['value' => 'due_in_24h', 'label' => 'Due in next 24 hours'],
                    ['value' => 'due_in_7d', 'label' => 'Due in next 7 days'],
                ],
                'default' => 'due_today',
                'required' => true,
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'lead' => 'array',
            'follow_up' => [
                'id' => 'string',
                'due_at' => 'datetime',
                'note' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        // The due-window matching is the responsibility of the LeadHub
        // dispatcher (it only fires events for leads inside a window).
        // We assume the event already represents a matching follow-up.
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $payload = is_array($event) ? $event : [
            'lead' => $event->lead ?? [],
            'follow_up' => $event->follow_up ?? [],
        ];

        return AutomationContext::make([
            'lead' => $payload['lead'] ?? [],
            'follow_up' => $payload['follow_up'] ?? [],
            'window' => $config['window'] ?? null,
        ]);
    }
}
