<?php

namespace Goldnead\StatamicAutomations\Integrations\WebhookManager;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Sends a payload through a configured Webhook Manager destination.
 *
 * Only registered when the Webhook Manager addon is detected — this
 * action delegates transport, signing, retry and logging to the
 * Webhook Manager package and only stores the delivery reference
 * inside the Automation run.
 */
class WebhookManagerSendAction implements AutomationAction
{
    public function __construct(protected WebhookManagerAdapter $adapter)
    {
    }

    public static function handle(): string
    {
        return 'webhook_manager.send';
    }

    public static function label(): string
    {
        return 'Send Webhook (via Webhook Manager)';
    }

    public static function description(): ?string
    {
        return 'Dispatches a payload to a configured Webhook Manager destination, inheriting transport, signing, retry and logs.';
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
                'required' => true,
            ],
            [
                'handle' => 'payload',
                'label' => 'Payload (JSON)',
                'type' => 'textarea',
                'required' => false,
                'help' => 'Either valid JSON or a tokenized JSON string. If empty, the full automation context is used.',
            ],
            [
                'handle' => 'headers',
                'label' => 'Additional headers',
                'type' => 'key_value',
                'required' => false,
            ],
            [
                'handle' => 'retry_policy',
                'label' => 'Retry policy',
                'type' => 'select',
                'options' => [
                    ['value' => 'default', 'label' => 'Use destination default'],
                    ['value' => 'no_retry', 'label' => 'No retry'],
                ],
                'default' => 'default',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $destination = $config['destination'] ?? null;
        if (empty($destination)) {
            return ActionResult::failed('Webhook destination is required.');
        }

        $payload = $this->payload($config['payload'] ?? null, $context);
        $options = [
            'headers' => $config['headers'] ?? [],
            'retry_policy' => $config['retry_policy'] ?? 'default',
        ];

        if ($context->isTestMode() && ! config('automations.test_mode.send_real_webhooks', false)) {
            return ActionResult::success([
                'preview' => [
                    'destination' => $destination,
                    'payload' => $payload,
                    'options' => $options,
                ],
                'note' => 'Test mode — Webhook Manager dispatch skipped.',
            ]);
        }

        $result = $this->adapter->dispatch($destination, $payload, $options);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Webhook dispatch failed.', [
                'destination' => $destination,
            ]);
        }

        return ActionResult::success([
            'destination' => $destination,
            'delivery_id' => $result['delivery_id'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
    }

    protected function payload(mixed $raw, AutomationContext $context): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $context->all();
    }
}
