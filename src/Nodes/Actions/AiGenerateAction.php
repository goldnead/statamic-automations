<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Licensing\LicenseManager;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Http;

/**
 * Generate text with an LLM (Anthropic Claude Messages API).
 *
 * The prompt and system message are token-resolved by the executor before
 * they reach here, so you can feed any context value into the model:
 *
 *     "Summarise this enquiry in one sentence: {{ trigger.form.message }}"
 *
 * The generated text is stored under this node's output as `text`, readable
 * downstream as {{ <node>.text }}, and — when "Store as variable" is set —
 * also copied into {{ vars.<name> }}.
 *
 * This is a Pro-tier action. Configure credentials in config/automations.php
 * under the `ai` block (never hard-code an API key in an automation).
 */
class AiGenerateAction implements AutomationAction
{
    public function __construct(protected LicenseManager $license) {}

    public static function handle(): string
    {
        return 'ai_generate';
    }

    public static function label(): string
    {
        return 'Generate with AI';
    }

    public static function description(): ?string
    {
        return 'Calls a Claude model to generate text from a token-resolved prompt.';
    }

    public static function group(): string
    {
        return 'AI';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'prompt',
                'label' => 'Prompt',
                'type' => 'textarea',
                'required' => true,
                'tokenable' => true,
                'help' => 'The user message sent to the model. May contain tokens.',
            ],
            [
                'handle' => 'system',
                'label' => 'System instructions',
                'type' => 'textarea',
                'required' => false,
                'help' => 'Optional system prompt that steers tone and format.',
            ],
            [
                'handle' => 'model',
                'label' => 'Model',
                'type' => 'text',
                'required' => false,
                'help' => 'Override the model id. Defaults to the configured ai.model.',
            ],
            [
                'handle' => 'max_tokens',
                'label' => 'Max tokens',
                'type' => 'integer',
                'required' => false,
                'help' => 'Upper bound on the generated length. Defaults to ai.max_tokens.',
            ],
            [
                'handle' => 'store_as',
                'label' => 'Store as variable',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional {{ vars.<name> }} to also hold the generated text.',
            ],
        ];
    }

    /**
     * Variables this action exposes downstream, e.g. {{ node.text }}.
     * Mirrors the keys returned on the success path of execute().
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'text' => 'string',
            'model' => 'string',
            'stop_reason' => 'string',
            'usage' => 'array',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        if ($this->isProGated() && ! $this->license->isPro()) {
            return ActionResult::failed('The AI action requires a Pro license.');
        }

        $prompt = trim((string) ($config['prompt'] ?? ''));
        if ($prompt === '') {
            return ActionResult::failed('AI prompt is required.');
        }

        $model = (string) ($config['model'] ?? '') ?: (string) config('automations.ai.model', '');
        $maxTokens = (int) ($config['max_tokens'] ?? 0) ?: (int) config('automations.ai.max_tokens', 1024);
        $system = trim((string) ($config['system'] ?? ''));
        $storeAs = trim((string) ($config['store_as'] ?? ''));

        if ($model === '') {
            return ActionResult::failed('No AI model configured (automations.ai.model).');
        }

        // In test mode we never spend tokens unless explicitly allowed.
        if ($context->isTestMode() && ! config('automations.test_mode.call_real_ai', false)) {
            return $this->store($context, $storeAs, '', [
                'preview' => [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $system ?: null,
                    'prompt' => $prompt,
                ],
                'note' => 'Test mode — AI not called.',
            ]);
        }

        $apiKey = (string) config('automations.ai.api_key', '');
        if ($apiKey === '') {
            return ActionResult::failed('No AI API key configured (automations.ai.api_key).');
        }

        $messages = [['role' => 'user', 'content' => $prompt]];

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('automations.ai.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('automations.ai.timeout', 30))
                ->baseUrl(rtrim((string) config('automations.ai.base_url', 'https://api.anthropic.com'), '/'))
                ->post('/v1/messages', $body);
        } catch (\Throwable $e) {
            return ActionResult::failed('AI request failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? "HTTP {$response->status()}";

            return ActionResult::failed("AI request rejected: {$error}");
        }

        $text = $this->extractText($response->json());

        return $this->store($context, $storeAs, $text, [
            'text' => $text,
            'model' => $model,
            'stop_reason' => $response->json('stop_reason'),
            'usage' => $response->json('usage'),
        ]);
    }

    /**
     * Join all text blocks from the Messages API response content array.
     */
    protected function extractText(?array $payload): string
    {
        $blocks = $payload['content'] ?? [];
        if (! is_array($blocks)) {
            return '';
        }

        $parts = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return trim(implode('', $parts));
    }

    protected function store(AutomationContext $context, string $storeAs, string $text, array $output): ActionResult
    {
        if ($storeAs !== '' && $text !== '') {
            $context->set("vars.{$storeAs}", $text);
        }

        return ActionResult::success($output);
    }

    protected function isProGated(): bool
    {
        return (bool) config('automations.features.ai_action_requires_pro', false);
    }
}
