<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AiClient;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\AiRequestException;
use Goldnead\StatamicAutomations\Support\JsonSchemaCheck;

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
 * With "Output" set to JSON the reply is decoded as an object, checked against
 * the node's schema and exposed field by field: {{ <node>.json.<field> }}.
 *
 * Model, max tokens and timeout can be set per node; empty falls back to the
 * `ai` block in config/automations.php. A long input (a 90-minute transcript)
 * needs a long timeout, which is only safe in a queued run — FlowValidator
 * refuses an automation that reaches this node from a synchronous trigger.
 *
 * Configure credentials in config/automations.php under the `ai` block
 * (never hard-code an API key in an automation).
 */
class AiGenerateAction implements AutomationAction
{
    public function __construct(protected AiClient $client) {}

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
                'handle' => 'timeout',
                'label' => 'Timeout (seconds)',
                'type' => 'integer',
                'required' => false,
                'help' => 'How long to wait for the model. Defaults to ai.timeout. Long inputs need minutes, not seconds; the queue worker must allow it (see docs).',
            ],
            [
                'handle' => 'output_mode',
                'label' => 'Output',
                'type' => 'select',
                'options' => [
                    ['value' => 'text', 'label' => 'Text'],
                    ['value' => 'json', 'label' => 'JSON object'],
                ],
                'default' => 'text',
                'required' => false,
                'help' => 'JSON: the reply must be an object, is checked against the schema below and every top-level field becomes readable as {{ nodes.<node>.json.<field> }}.',
            ],
            [
                'handle' => 'json_schema',
                'label' => 'JSON schema',
                'type' => 'textarea',
                'required' => false,
                'resolve_tokens' => false,
                'help' => 'Only for JSON output. A JSON Schema (type, properties, required, items, enum), e.g. {"type":"object","required":["zusammenfassung"],"properties":{"zusammenfassung":{"type":"string"},"aufgaben":{"type":"array"}}}',
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

    /**
     * The same, for one node's configuration: in JSON mode the schema's
     * top-level fields appear under `json`. The editor mirrors this in
     * useNodeVariables.js because it only has the static schema above.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function outputSchemaFor(array $config): array
    {
        $schema = self::outputSchema();

        if (($config['output_mode'] ?? 'text') !== 'json') {
            return $schema;
        }

        $parsed = self::parseSchema($config);
        $fields = is_array($parsed) ? array_keys((array) ($parsed['properties'] ?? [])) : [];
        $schema['json'] = $fields === []
            ? 'array'
            : array_combine($fields, array_map(fn ($f) => (string) ($parsed['properties'][$f]['type'] ?? 'mixed'), $fields));

        return $schema;
    }

    /**
     * Seconds this node may wait for the model, or null when the configured
     * value is unusable. Empty means the global `ai.timeout`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function timeoutFor(array $config): ?int
    {
        $raw = $config['timeout'] ?? null;

        if ($raw === null || $raw === '') {
            return max(1, (int) config('automations.ai.timeout', 30));
        }

        if (! is_numeric($raw) || (float) $raw != (int) $raw || (int) $raw < 1) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Problems with a node's own settings, independent of any run. Shared by
     * the flow validator (at save/enable time) and execute() (as a backstop).
     *
     * @param  array<string, mixed>  $config
     * @return list<array{code: string, field: string, message: string}>
     */
    public static function configProblems(array $config): array
    {
        $problems = [];
        $max = (int) config('automations.ai.max_timeout', 900);
        $timeout = self::timeoutFor($config);

        if ($timeout === null) {
            $problems[] = ['code' => 'invalid_ai_timeout', 'field' => 'timeout', 'message' => 'AI timeout must be a positive whole number of seconds.'];
        } elseif ($timeout > $max) {
            $problems[] = ['code' => 'invalid_ai_timeout', 'field' => 'timeout', 'message' => "AI timeout of {$timeout} s exceeds the allowed maximum of {$max} s (automations.ai.max_timeout)."];
        }

        $mode = (string) ($config['output_mode'] ?? 'text');
        if (! in_array($mode, ['text', 'json'], true)) {
            $problems[] = ['code' => 'invalid_ai_output_mode', 'field' => 'output_mode', 'message' => "AI output must be 'text' or 'json'."];
        } elseif ($mode === 'json' && self::parseSchema($config) === false) {
            $problems[] = ['code' => 'invalid_ai_schema', 'field' => 'json_schema', 'message' => 'AI JSON schema is not valid JSON.'];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null|false null when no schema is set, false when it does not parse
     */
    protected static function parseSchema(array $config): array|null|false
    {
        $raw = trim((string) ($config['json_schema'] ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : false;
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $prompt = trim((string) ($config['prompt'] ?? ''));
        if ($prompt === '') {
            return ActionResult::failed('AI prompt is required.');
        }

        if ($problems = self::configProblems($config)) {
            return ActionResult::failed($problems[0]['message']);
        }

        $model = (string) ($config['model'] ?? '') ?: (string) config('automations.ai.model', '');
        $maxTokens = (int) ($config['max_tokens'] ?? 0) ?: (int) config('automations.ai.max_tokens', 1024);
        $timeout = (int) self::timeoutFor($config);
        $system = trim((string) ($config['system'] ?? ''));
        $storeAs = trim((string) ($config['store_as'] ?? ''));
        $jsonMode = ($config['output_mode'] ?? 'text') === 'json';
        $schema = $jsonMode ? self::parseSchema($config) : null;

        if ($model === '') {
            return ActionResult::failed('No AI model configured (automations.ai.model).');
        }

        // In test mode we never spend tokens unless explicitly allowed.
        if ($context->isTestMode() && ! config('automations.test_mode.call_real_ai', false)) {
            return $this->store($context, $storeAs, '', [
                'preview' => [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'timeout' => $timeout,
                    'output' => $jsonMode ? 'json' : 'text',
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

        if ($jsonMode) {
            $system = trim($system."\n\n".$this->jsonInstruction($schema));
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        try {
            $payload = $this->client->messages($body, $timeout);
        } catch (AiRequestException $e) {
            return ActionResult::failed($e->getMessage());
        }

        $text = $this->extractText($payload);
        $output = [
            'text' => $text,
            'model' => $model,
            'stop_reason' => $payload['stop_reason'] ?? null,
            'usage' => $payload['usage'] ?? null,
        ];

        if (! $jsonMode) {
            return $this->store($context, $storeAs, $text, $output);
        }

        $decoded = $this->decodeObject($text);
        if (is_string($decoded)) {
            return ActionResult::failed($decoded.($output['stop_reason'] === 'max_tokens' ? ' The reply was cut off at max_tokens; raise it.' : ''));
        }

        if (is_array($schema) && ($violations = JsonSchemaCheck::violations($decoded, $schema))) {
            return ActionResult::failed('AI reply does not match the JSON schema: '.implode('; ', array_slice($violations, 0, 5)));
        }

        $output['json'] = $decoded;

        return $this->store($context, $storeAs, $text, $output);
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    protected function jsonInstruction(?array $schema): string
    {
        $instruction = 'Reply with a single JSON object and nothing else: no prose before or after, no code fences.';

        if ($schema !== null) {
            $instruction .= ' The object must conform to this JSON Schema: '.json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $instruction;
    }

    /**
     * Decode the reply as a JSON object. Returns the array, or an error
     * message string when it is not one.
     *
     * @return array<string, mixed>|string
     */
    protected function decodeObject(string $text): array|string
    {
        $raw = trim($text);

        // Models like to wrap JSON in a fence even when told not to.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'AI reply is not valid JSON: '.json_last_error_msg().'.';
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return 'AI reply is valid JSON but not an object.';
        }

        return $decoded;
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
}
