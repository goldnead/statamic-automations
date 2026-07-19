<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Generic, config-driven trigger that turns an arbitrary application event
 * into an automation trigger.
 *
 * A single instance is created per registered event trigger, carrying its own
 * definition (handle, label, payload mapping, optional matcher). It is
 * registered as a *configured instance* in the TriggerRegistry and described
 * per-handle via NodeRegistry meta, so the same generic class can back any
 * number of distinct triggers without a bespoke class each.
 *
 * The static contract methods return generic placeholders: they are never the
 * source of truth for a registered event trigger (NodeRegistry meta supplies
 * the per-handle description, the dispatcher calls the instance methods).
 *
 * @see \Goldnead\StatamicAutomations\Automations::registerEventTrigger()
 */
class EventTrigger implements AutomationTrigger
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(protected array $definition = [])
    {
    }

    public static function handle(): string
    {
        return 'event_trigger';
    }

    public static function label(): string
    {
        return 'Event Trigger';
    }

    public static function description(): ?string
    {
        return 'Fires when a registered application event is dispatched.';
    }

    public static function group(): string
    {
        return 'Events';
    }

    public static function schema(): array
    {
        return [];
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function outputSchema(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    public function matches(object|array $event, array $config): bool
    {
        $matcher = $this->definition['matches'] ?? null;

        if (is_callable($matcher)) {
            return (bool) $matcher($event, $config);
        }

        if (is_string($matcher) && $matcher !== '' && class_exists($matcher)) {
            $instance = app($matcher);

            if (is_callable($instance)) {
                return (bool) $instance($event, $config);
            }

            if (method_exists($instance, 'matches')) {
                return (bool) $instance->matches($event, $config);
            }
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make($this->mapPayload($event));
    }

    /**
     * Map the raw event to a context payload according to the definition's
     * `payload` directive: a callable, an invokable/`map()` class-string, a
     * dot-path string (wraps the extracted value under its root key), or `*`
     * (dumps public properties / the array as-is).
     *
     * @return array<string, mixed>
     */
    protected function mapPayload(object|array $event): array
    {
        $payload = $this->definition['payload'] ?? '*';

        if (is_callable($payload)) {
            return $this->asArray($payload($event));
        }

        if (is_string($payload) && $payload !== '' && $payload !== '*' && class_exists($payload)) {
            $mapper = app($payload);

            if (is_callable($mapper)) {
                return $this->asArray($mapper($event));
            }

            if (method_exists($mapper, 'map')) {
                return $this->asArray($mapper->map($event));
            }
        }

        if (! is_string($payload) || $payload === '*' || $payload === '') {
            return $this->dumpPublicProperties($event);
        }

        // Dot-path: wrap the extracted value under its root segment so tokens
        // like {{ order.id }} resolve for `payload: 'order'`.
        $rootKey = explode('.', $payload)[0];

        return [$rootKey => $this->extractPath($event, $payload)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return $this->dumpPublicProperties($value);
        }

        return ['payload' => $value];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dumpPublicProperties(object|array $event): array
    {
        if (is_array($event)) {
            return $event;
        }

        if (method_exists($event, 'toArray')) {
            return (array) $event->toArray();
        }

        return get_object_vars($event);
    }

    protected function extractPath(object|array $event, string $path): mixed
    {
        $segments = explode('.', $path);
        $value = $event;

        foreach ($segments as $segment) {
            if (is_array($value)) {
                $value = $value[$segment] ?? null;
            } elseif (is_object($value)) {
                $value = $value->{$segment} ?? null;
            } else {
                return null;
            }
        }

        if (is_object($value)) {
            return $this->dumpPublicProperties($value);
        }

        return $value;
    }
}
