<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Contracts\AiClient;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API over Laravel's HTTP client. Credentials, base URL and
 * API version come from `automations.ai`; the timeout is per call.
 */
class AnthropicClient implements AiClient
{
    public function messages(array $body, int $timeout): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('automations.ai.api_key', ''),
                'anthropic-version' => (string) config('automations.ai.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
                ->timeout($timeout)
                ->baseUrl(rtrim((string) config('automations.ai.base_url', 'https://api.anthropic.com'), '/'))
                ->post('/v1/messages', $body);
        } catch (\Throwable $e) {
            $hint = stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'cURL error 28') !== false
                ? " (no answer within {$timeout} s; raise the node's Timeout)"
                : '';

            throw new AiRequestException('AI request failed: '.$e->getMessage().$hint, 0, $e);
        }

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? "HTTP {$response->status()}";

            throw new AiRequestException("AI request rejected: {$error}");
        }

        return (array) $response->json();
    }
}
