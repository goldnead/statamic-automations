<?php

namespace Goldnead\StatamicAutomations\Integrations\WebhookManager;

/**
 * Thin adapter that talks to the Webhook Manager addon's public API
 * if it is installed.
 *
 * The expected surface is small and intentionally resilient — the
 * adapter accepts either a Facade (with static `destinations()` /
 * `dispatch()` methods) or a service class accessible via the Laravel
 * container under the same FQCN.
 */
class WebhookManagerAdapter
{
    /**
     * Return the available destinations as a flat array of options
     * suitable for a CP <select>: [{ value, label }].
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function destinations(): array
    {
        $service = $this->resolve();
        if ($service === null) {
            return [];
        }

        if (method_exists($service, 'destinations')) {
            $raw = $this->invoke($service, 'destinations');

            return $this->normalizeOptions(is_iterable($raw) ? $raw : []);
        }

        return [];
    }

    /**
     * Dispatch a payload to the configured destination handle.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options  e.g. ['headers' => [...], 'retry_policy' => 'default']
     * @return array{ok: bool, delivery_id?: string, status?: int, error?: string}
     */
    public function dispatch(string $destinationHandle, array $payload, array $options = []): array
    {
        $service = $this->resolve();
        if ($service === null) {
            return ['ok' => false, 'error' => 'Webhook Manager not installed.'];
        }

        if (method_exists($service, 'dispatch')) {
            try {
                $result = $this->invoke($service, 'dispatch', [$destinationHandle, $payload, $options]);

                return $this->normalizeDispatchResult($result);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['ok' => false, 'error' => 'Webhook Manager facade does not implement dispatch().'];
    }

    /**
     * Resolve the configured Webhook Manager service. Real Laravel
     * facades proxy everything through __callStatic, so method_exists()
     * on the facade class is always false — for those we resolve the
     * facade root instance instead. Plain classes (or pre-built objects)
     * with real methods are returned as-is.
     *
     * @return object|class-string|null
     */
    protected function resolve(): object|string|null
    {
        $candidates = (array) config('automations.integrations.webhook_manager.facade', [
            'Goldnead\\WebhookManager\\Facades\\WebhookManager',
        ]);

        foreach ($candidates as $class) {
            if (is_object($class)) {
                return $class;
            }

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, \Illuminate\Support\Facades\Facade::class)) {
                try {
                    $root = $class::getFacadeRoot();
                    if (is_object($root)) {
                        return $root;
                    }
                } catch (\Throwable) {
                    // Accessor not bound — treat as unavailable.
                }

                continue;
            }

            return $class;
        }

        return null;
    }

    /**
     * Invoke a method on the resolved service — instance call for
     * objects (facade roots), static call for plain class strings.
     *
     * @param  object|class-string  $service
     * @param  array<int, mixed>  $args
     */
    protected function invoke(object|string $service, string $method, array $args = []): mixed
    {
        return is_object($service)
            ? $service->{$method}(...$args)
            : $service::$method(...$args);
    }

    /**
     * @param  iterable<mixed>  $raw
     * @return array<int, array{value: string, label: string}>
     */
    protected function normalizeOptions(iterable $raw): array
    {
        $out = [];

        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $value = $entry['handle'] ?? $entry['value'] ?? null;
                $label = $entry['name'] ?? $entry['label'] ?? $value;
            } elseif (is_object($entry)) {
                $value = method_exists($entry, 'handle') ? $entry->handle() : ($entry->handle ?? null);
                $label = method_exists($entry, 'name') ? $entry->name() : ($entry->name ?? $value);
            } else {
                $value = $entry;
                $label = $entry;
            }

            if ($value !== null) {
                $out[] = ['value' => (string) $value, 'label' => (string) $label];
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, delivery_id?: string, status?: int, error?: string}
     */
    protected function normalizeDispatchResult(mixed $result): array
    {
        if (is_array($result)) {
            return [
                'ok' => (bool) ($result['ok'] ?? true),
                'delivery_id' => $result['delivery_id'] ?? $result['id'] ?? null,
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        }

        if (is_object($result)) {
            return [
                'ok' => method_exists($result, 'ok') ? (bool) $result->ok() : true,
                'delivery_id' => method_exists($result, 'id') ? $result->id() : null,
                'status' => method_exists($result, 'status') ? $result->status() : null,
            ];
        }

        return ['ok' => true];
    }
}
