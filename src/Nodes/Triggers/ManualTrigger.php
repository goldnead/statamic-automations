<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class ManualTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'manual';
    }

    public static function label(): string
    {
        return 'Manual Trigger';
    }

    public static function description(): ?string
    {
        return 'Runs only when triggered manually (e.g. for testing).';
    }

    public static function group(): string
    {
        return 'Manual';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'sample_payload',
                'label' => 'Sample payload (JSON)',
                'type' => 'textarea',
                'required' => false,
                'help' => 'Optional sample data merged into the context when the automation is run manually.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'site' => ['handle' => 'string'],
            'meta' => ['timestamp' => 'datetime'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        // Manual triggers never match incoming Laravel events — they
        // are dispatched explicitly.
        return false;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $payload = is_array($event) ? $event : [];

        $sample = $config['sample_payload'] ?? null;
        if (is_string($sample) && $sample !== '') {
            $decoded = json_decode($sample, true);
            if (is_array($decoded)) {
                $payload = array_replace_recursive($decoded, $payload);
            }
        }

        return AutomationContext::make($payload);
    }
}
