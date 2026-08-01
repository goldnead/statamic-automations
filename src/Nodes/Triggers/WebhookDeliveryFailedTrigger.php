<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Starts a flow when Webhook Manager gives up on an outbound delivery —
 * the delivery has exhausted its retry strategy and failed for good.
 * Registered only when Webhook Manager is installed.
 *
 * Backed by Webhook Manager's `DeliveryFailedTerminally` event, whose FQCN is
 * configurable (`automations.integrations.webhook_manager.outbound_failed_event`)
 * for the same reason the inbound bridge is: the exact class name belongs to
 * the other package. The event carries a Delivery, from which the destination
 * handle, the attempt count and the last error are read defensively, so a
 * future reshape of that model degrades to empty values instead of a fatal.
 */
class WebhookDeliveryFailedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'webhook_manager.outbound_failed';
    }

    public static function label(): string
    {
        return 'Outbound Webhook Failed';
    }

    public static function description(): ?string
    {
        return 'Triggered when Webhook Manager has exhausted its retries for an outbound delivery.';
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
                'handle' => 'destination',
                'label' => 'Destination',
                'type' => 'select',
                'options_source' => 'webhook_manager.destinations',
                'required' => false,
                'help' => 'Leave empty to match any outbound destination.',
            ],
            [
                'handle' => 'min_attempts',
                'label' => 'Minimum attempts',
                'type' => 'integer',
                'required' => false,
                'default' => 1,
                'help' => 'Only fire once the delivery has been tried at least this many times. '
                    .'Use it to ignore destinations that fail on the first try but are configured without retries.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'webhook' => [
                'destination' => 'string',
                'destination_name' => 'string',
                'url' => 'string',
                'attempts' => 'integer',
                'status' => 'integer',
                'error' => 'string',
                'delivery_id' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $delivery = $this->delivery($event);

        $expected = $config['destination'] ?? null;
        if (! empty($expected) && $this->destinationHandle($delivery) !== $expected) {
            return false;
        }

        $minAttempts = $config['min_attempts'] ?? null;
        if ($minAttempts !== null && $minAttempts !== '') {
            return $this->attempts($delivery) >= (int) $minAttempts;
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $delivery = $this->delivery($event);
        $destination = $this->value($delivery, 'outboundWebhook');

        return AutomationContext::make([
            'webhook' => [
                'destination' => $this->destinationHandle($delivery),
                'destination_name' => $this->stringOrNull($this->value($destination, 'name')),
                'url' => $this->stringOrNull($this->value($delivery, 'request_url')),
                'attempts' => $this->attempts($delivery),
                'status' => $this->value($delivery, 'response_status'),
                'error' => $this->stringOrNull($this->value($delivery, 'error_message')),
                'delivery_id' => $this->stringOrNull($this->value($delivery, 'uuid')),
            ],
        ]);
    }

    /**
     * The event wraps the delivery; a bare delivery (or array) is accepted too
     * so the trigger stays testable and survives a differently shaped event.
     */
    protected function delivery(object|array $event): object|array|null
    {
        $delivery = $this->value($event, 'delivery');

        if (is_object($delivery) || is_array($delivery)) {
            return $delivery;
        }

        return $event;
    }

    protected function destinationHandle(object|array|null $delivery): ?string
    {
        $destination = $this->value($delivery, 'outboundWebhook');

        return $this->stringOrNull($this->value($destination, 'handle'));
    }

    protected function attempts(object|array|null $delivery): int
    {
        return (int) ($this->value($delivery, 'attempts') ?? 0);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Read a value whether the carrier is an array, exposes a public property
     * (including an Eloquent attribute or relation) or an accessor method of
     * the same name.
     */
    protected function value(object|array|null $carrier, string $key): mixed
    {
        if ($carrier === null) {
            return null;
        }

        if (is_array($carrier)) {
            return $carrier[$key] ?? null;
        }

        if (isset($carrier->{$key})) {
            return $carrier->{$key};
        }

        if (method_exists($carrier, $key)) {
            $result = $carrier->{$key}();

            // A relation accessed as a method returns the relation object, not
            // the related model. Only the property form is meaningful there.
            return $result instanceof Relation
                ? $result->getResults()
                : $result;
        }

        return null;
    }
}
