<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Http;

class SimpleWebhookAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'send_webhook';
    }

    public static function label(): string
    {
        return 'Send Webhook (Simple)';
    }

    public static function description(): ?string
    {
        return 'POSTs a JSON payload to a URL. When the Webhook Manager addon is installed, prefer the dedicated destination action instead.';
    }

    public static function group(): string
    {
        return 'HTTP';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            ['handle' => 'url', 'label' => 'URL', 'type' => 'text', 'required' => true],
            [
                'handle' => 'method',
                'label' => 'Method',
                'type' => 'select',
                'options' => ['POST', 'PUT', 'PATCH'],
                'default' => 'POST',
            ],
            [
                'handle' => 'headers',
                'label' => 'Headers',
                'type' => 'key_value',
                'required' => false,
            ],
            [
                'handle' => 'payload',
                'label' => 'Payload (JSON)',
                'type' => 'textarea',
                'required' => false,
                'help' => 'Either valid JSON or a tokenized JSON string.',
            ],
            [
                'handle' => 'timeout',
                'label' => 'Timeout (seconds)',
                'type' => 'number',
                'default' => 10,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $url = $config['url'] ?? null;
        $method = strtoupper($config['method'] ?? 'POST');
        $headers = $this->normalizeHeaders($config['headers'] ?? []);
        $timeout = (int) ($config['timeout'] ?? 10);
        $payload = $this->decodePayload($config['payload'] ?? null, $context);

        if (empty($url)) {
            return ActionResult::failed('Webhook URL is required.');
        }

        $preview = [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'payload' => $payload,
        ];

        if ($context->isTestMode() && ! config('automations.test_mode.send_real_webhooks', false)) {
            return ActionResult::success([
                'preview' => $preview,
                'note' => 'Test mode — webhook not sent.',
            ]);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->send($method, $url, ['json' => $payload]);

            return ActionResult::success([
                'status' => $response->status(),
                'body' => $response->body(),
                'headers' => $response->headers(),
                'preview' => $preview,
            ]);
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage(), $preview);
        }
    }

    /**
     * Normalize a key/value style array to a flat ['Header-Name' => 'value'] map.
     */
    protected function normalizeHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        // Allow [{key, value}, ...] AND ['Header' => 'value'] shapes.
        if (array_is_list($headers)) {
            $out = [];
            foreach ($headers as $row) {
                if (is_array($row) && isset($row['key'])) {
                    $out[$row['key']] = $row['value'] ?? '';
                }
            }

            return $out;
        }

        return $headers;
    }

    protected function decodePayload(mixed $raw, AutomationContext $context): array
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
