<?php

namespace Goldnead\StatamicAutomations\Integrations\WebhookManager;

/**
 * Thin adapter that talks to the Webhook Manager addon's public API
 * if it is installed.
 *
 * Outbound destinations and dispatching are NOT exposed on the Webhook
 * Manager facade root (that class only carries the registry methods).
 * The real surface is the container-bound outbound webhook repository
 * (`OutboundWebhookRepositoryInterface`) plus the dispatch action
 * (`DispatchOutboundWebhookAction`). Both FQCNs are configurable and the
 * adapter degrades gracefully to an empty list / "not installed" result
 * when Webhook Manager is genuinely absent.
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
        $repository = $this->resolveOutboundRepository();
        if ($repository === null || ! method_exists($repository, 'all')) {
            return [];
        }

        $raw = $repository->all();

        return $this->normalizeOptions(is_iterable($raw) ? $raw : []);
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
        $repository = $this->resolveOutboundRepository();
        if ($repository === null || ! method_exists($repository, 'findByHandle')) {
            return ['ok' => false, 'error' => 'Webhook Manager not installed.'];
        }

        $dispatchClass = (string) config(
            'automations.integrations.webhook_manager.dispatch_action',
            'Goldnead\\WebhookManager\\Domain\\OutboundWebhook\\Actions\\DispatchOutboundWebhookAction'
        );
        $eventClass = 'Goldnead\\WebhookManager\\ValueObjects\\TriggerEvent';
        $contextClass = 'Goldnead\\WebhookManager\\ValueObjects\\ExecutionContext';

        if ($dispatchClass === '' || ! class_exists($dispatchClass) || ! class_exists($eventClass) || ! class_exists($contextClass)) {
            return ['ok' => false, 'error' => 'Webhook Manager dispatch action unavailable.'];
        }

        try {
            $hook = $repository->findByHandle($destinationHandle);
            if ($hook === null) {
                return ['ok' => false, 'error' => "Outbound webhook '{$destinationHandle}' not found."];
            }

            // TriggerEvent(triggerHandle, sourceType, sourceReference, payload)
            $event = new $eventClass('automations.dispatch', 'automations', null, $payload);
            $context = new $contextClass($event, ['options' => $options]);

            $dispatch = app()->make($dispatchClass);
            $deliveryId = $dispatch($hook, $context);

            return [
                'ok' => true,
                'delivery_id' => $deliveryId !== null ? (string) $deliveryId : null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve the Webhook Manager outbound webhook repository from the
     * container. The interface is bound by Webhook Manager's service
     * provider (to the active Eloquent / FlatFile driver). When Webhook
     * Manager is genuinely absent the interface is neither bound nor
     * declared, so we return null and callers degrade gracefully.
     */
    protected function resolveOutboundRepository(): ?object
    {
        $interface = (string) config(
            'automations.integrations.webhook_manager.outbound_repository',
            'Goldnead\\WebhookManager\\Contracts\\Repositories\\OutboundWebhookRepositoryInterface'
        );

        if ($interface === '') {
            return null;
        }

        // Only attempt resolution when the binding exists (real install or a
        // test fake) or the interface is actually declared. This avoids a
        // BindingResolutionException when the addon is absent.
        if (! app()->bound($interface) && ! interface_exists($interface) && ! class_exists($interface)) {
            return null;
        }

        try {
            $repository = app()->make($interface);

            return is_object($repository) ? $repository : null;
        } catch (\Throwable) {
            return null;
        }
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
}
