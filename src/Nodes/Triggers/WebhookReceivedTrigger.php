<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Starts a flow when the Webhook Manager addon receives a validated
 * inbound request. Registered only when Webhook Manager is installed.
 *
 * The inbound event class is configurable
 * (automations.integrations.webhook_manager.inbound_event) so this stays
 * resilient to the exact FQCN Webhook Manager ships. The event is
 * expected to expose an endpoint handle plus a payload and headers
 * (as properties, accessors or array keys).
 */
class WebhookReceivedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'webhook_received';
    }

    public static function label(): string
    {
        return 'Webhook Received';
    }

    public static function description(): ?string
    {
        return 'Triggered when Webhook Manager receives an inbound request on an endpoint.';
    }

    public static function group(): string
    {
        return 'Webhook Manager';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'endpoint',
                'label' => 'Inbound endpoint',
                'type' => 'select',
                'options_source' => 'webhook_manager.inbound_endpoints',
                'required' => false,
                'help' => 'Leave empty to match any inbound endpoint.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'webhook' => [
                'endpoint' => 'string',
                'payload' => 'array',
                'headers' => 'array',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $expected = $config['endpoint'] ?? null;
        if (empty($expected)) {
            return true;
        }

        return $this->value($event, 'endpoint') === $expected;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'webhook' => [
                'endpoint' => $this->value($event, 'endpoint'),
                'payload' => $this->value($event, 'payload') ?? [],
                'headers' => $this->value($event, 'headers') ?? [],
            ],
        ]);
    }

    /**
     * Read a value from the event whether it is an array, has a public
     * property, or exposes an accessor method of the same name.
     */
    protected function value(object|array $event, string $key): mixed
    {
        if (is_array($event)) {
            return $event[$key] ?? null;
        }

        if (isset($event->{$key})) {
            return $event->{$key};
        }

        if (method_exists($event, $key)) {
            return $event->{$key}();
        }

        return null;
    }
}
