<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class LeadCreatedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'leadhub.lead_created';
    }

    public static function label(): string
    {
        return 'Lead Created';
    }

    public static function description(): ?string
    {
        return 'Triggered when a new lead is created in LeadHub.';
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
                'handle' => 'source',
                'label' => 'Source filter',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional — only run when the lead came from this source.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'lead' => [
                'id' => 'string',
                'email' => 'string',
                'full_name' => 'string',
                'first_name' => 'string',
                'last_name' => 'string',
                'company' => 'string',
                'phone' => 'string',
                'status' => 'string',
                'tags' => 'array',
                'source' => 'string',
                'created_at' => 'datetime',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $source = $config['source'] ?? null;
        if (! $source) {
            return true;
        }

        $eventSource = $this->extractLead($event)['source'] ?? null;

        return $eventSource === $source;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'lead' => $this->extractLead($event),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractLead(object|array $event): array
    {
        if (is_array($event)) {
            return $event['lead'] ?? $event;
        }

        $lead = $event->lead ?? null;
        if (is_object($lead)) {
            $array = method_exists($lead, 'toArray') ? $lead->toArray() : [];
            if (! is_array($array)) {
                $array = [];
            }
            $array['id'] ??= method_exists($lead, 'id') ? $lead->id() : ($lead->id ?? null);
            $array['email'] ??= method_exists($lead, 'email') ? $lead->email() : ($lead->email ?? null);

            return $array;
        }

        return [];
    }
}
