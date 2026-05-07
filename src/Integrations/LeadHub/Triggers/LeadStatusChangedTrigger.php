<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class LeadStatusChangedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'leadhub.lead_status_changed';
    }

    public static function label(): string
    {
        return 'Lead Status Changed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a LeadHub lead transitions between statuses.';
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
                'handle' => 'old_status',
                'label' => 'From status',
                'type' => 'select',
                'options_source' => 'leadhub.statuses',
                'required' => false,
                'help' => 'Leave empty to match any previous status.',
            ],
            [
                'handle' => 'new_status',
                'label' => 'To status',
                'type' => 'select',
                'options_source' => 'leadhub.statuses',
                'required' => false,
                'help' => 'Leave empty to match any new status.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'lead' => 'array',
            'previous_status' => 'string',
            'new_status' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $old = $config['old_status'] ?? null;
        $new = $config['new_status'] ?? null;

        $payload = $this->extractPayload($event);

        if ($old && ($payload['previous_status'] ?? null) !== $old) {
            return false;
        }

        if ($new && ($payload['new_status'] ?? null) !== $new) {
            return false;
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $payload = $this->extractPayload($event);

        return AutomationContext::make([
            'lead' => $payload['lead'] ?? [],
            'previous_status' => $payload['previous_status'] ?? null,
            'new_status' => $payload['new_status'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractPayload(object|array $event): array
    {
        if (is_array($event)) {
            return $event;
        }

        return [
            'lead' => $event->lead ?? [],
            'previous_status' => $event->previous_status ?? null,
            'new_status' => $event->new_status ?? null,
        ];
    }
}
