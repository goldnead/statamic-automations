<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\AiGenerateAction;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('automations.ai.api_key', 'test-key');
    config()->set('automations.ai.model', 'claude-test');
});

function aiAction(): AiGenerateAction
{
    return app(AiGenerateAction::class);
}

it('registers in the AI group and supports test mode', function (): void {
    expect(AiGenerateAction::handle())->toBe('ai_generate');
    expect(AiGenerateAction::group())->toBe('AI');
    expect(AiGenerateAction::supportsTestMode())->toBeTrue();
});

it('calls the Messages API and stores the generated text', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Hello world']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ], 200),
    ]);

    $ctx = AutomationContext::make([]);
    $result = aiAction()->execute($ctx, ['prompt' => 'Say hi', 'store_as' => 'greeting']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['text'])->toBe('Hello world');
    expect($ctx->get('vars.greeting'))->toBe('Hello world');

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-test'
            && $request['messages'][0]['content'] === 'Say hi';
    });
});

it('includes the system prompt when provided', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200),
    ]);

    aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'hi',
        'system' => 'Be terse.',
    ]);

    Http::assertSent(fn ($request) => ($request['system'] ?? null) === 'Be terse.');
});

it('does not call the API in test mode by default', function (): void {
    Http::fake();

    $result = aiAction()->execute(
        AutomationContext::make([], testMode: true),
        ['prompt' => 'hi']
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toHaveKey('preview');
    Http::assertNothingSent();
});

it('fails gracefully when no API key is configured', function (): void {
    // Default config ships api_key => env('ANTHROPIC_API_KEY', '') — an
    // unconfigured install must produce a clear run-log error, not an
    // unhandled exception or an HTTP call with an empty key.
    config()->set('automations.ai.api_key', '');
    Http::fake();

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi']);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('automations.ai.api_key');
    Http::assertNothingSent();
});

it('fails without a prompt', function (): void {
    expect(aiAction()->execute(AutomationContext::make([]), [])->isFailed())->toBeTrue();
});

it('reports an API error as a failed result', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
    ]);

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi']);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('overloaded');
});

// --- per-node settings (issue #17) -----------------------------------------

use Goldnead\StatamicAutomations\Contracts\AiClient;
use Goldnead\StatamicAutomations\Support\AiRequestException;
use Illuminate\Http\Client\ConnectionException;

/**
 * A client that records what the action asked for and answers with `$reply`.
 */
function fakeAi(string $reply = 'ok', ?Throwable $throws = null): object
{
    $fake = new class($reply, $throws) implements AiClient
    {
        public array $body = [];

        public ?int $timeout = null;

        public function __construct(public string $reply, public ?Throwable $throws) {}

        public function messages(array $body, int $timeout): array
        {
            $this->body = $body;
            $this->timeout = $timeout;

            if ($this->throws) {
                throw $this->throws;
            }

            return ['content' => [['type' => 'text', 'text' => $this->reply]], 'stop_reason' => 'end_turn', 'usage' => []];
        }
    };

    app()->instance(AiClient::class, $fake);

    return $fake;
}

it('lets the node override timeout, model and max_tokens over the config', function (): void {
    config()->set('automations.ai.timeout', 30);
    config()->set('automations.ai.max_tokens', 1024);
    $fake = fakeAi();

    aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'hi', 'timeout' => 600, 'model' => 'claude-node', 'max_tokens' => 8000,
    ]);

    expect($fake->timeout)->toBe(600)
        ->and($fake->body['model'])->toBe('claude-node')
        ->and($fake->body['max_tokens'])->toBe(8000);
});

it('falls back to the config when the node leaves the settings empty', function (): void {
    config()->set('automations.ai.timeout', 45);
    config()->set('automations.ai.max_tokens', 2048);
    $fake = fakeAi();

    aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi', 'timeout' => '', 'model' => '', 'max_tokens' => '']);

    expect($fake->timeout)->toBe(45)
        ->and($fake->body['model'])->toBe('claude-test')
        ->and($fake->body['max_tokens'])->toBe(2048);
});

it('rejects a timeout that is not a positive whole number or exceeds the ceiling', function (mixed $timeout): void {
    config()->set('automations.ai.max_timeout', 900);
    $fake = fakeAi();

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi', 'timeout' => $timeout]);

    expect($result->isFailed())->toBeTrue()
        ->and($result->error)->toContain('timeout')
        ->and($fake->timeout)->toBeNull();
})->with([0, -5, 'soon', 12.5, 901]);

it('reports an HTTP timeout as a readable failure naming the limit', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi', 'timeout' => 5]);

    expect($result->isFailed())->toBeTrue()
        ->and($result->error)->toContain('AI request failed')
        ->and($result->error)->toContain('5 s');
});

it('passes a client failure through to the run log', function (): void {
    fakeAi(throws: new AiRequestException('AI request rejected: overloaded'));

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi']);

    expect($result->isFailed())->toBeTrue()->and($result->error)->toBe('AI request rejected: overloaded');
});

// --- JSON output -------------------------------------------------------------

function reviewSchema(): string
{
    return json_encode([
        'type' => 'object',
        'required' => ['zusammenfassung', 'aufgaben'],
        'properties' => [
            'zusammenfassung' => ['type' => 'string'],
            'aufgaben' => ['type' => 'array', 'items' => ['type' => 'string']],
            'details' => ['type' => 'object', 'required' => ['titel'], 'properties' => ['titel' => ['type' => 'string']]],
        ],
    ]);
}

it('decodes a JSON reply into output.json and tells the model about the schema', function (): void {
    $fake = fakeAi('{"zusammenfassung":"Gut gelaufen","aufgaben":["Angebot schicken"]}');

    $result = aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'Transkript …', 'system' => 'Du bist Coach.', 'output_mode' => 'json', 'json_schema' => reviewSchema(),
    ]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['json'])->toBe(['zusammenfassung' => 'Gut gelaufen', 'aufgaben' => ['Angebot schicken']])
        ->and($fake->body['system'])->toContain('Du bist Coach.')->toContain('zusammenfassung');
});

it('accepts a JSON reply wrapped in a code fence', function (): void {
    fakeAi("```json\n{\"zusammenfassung\":\"x\",\"aufgaben\":[]}\n```");

    $result = aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'p', 'output_mode' => 'json', 'json_schema' => reviewSchema(),
    ]);

    expect($result->isSuccess())->toBeTrue()->and($result->output['json']['zusammenfassung'])->toBe('x');
});

it('fails the node on invalid JSON, a non-object, and a schema violation', function (string $reply, string $expected): void {
    fakeAi($reply);

    $result = aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'p', 'output_mode' => 'json', 'json_schema' => reviewSchema(),
    ]);

    expect($result->isFailed())->toBeTrue()->and($result->error)->toContain($expected);
})->with([
    'invalid json' => ['Hier ist deine Zusammenfassung: …', 'not valid JSON'],
    'not an object' => ['["a","b"]', 'not an object'],
    'empty array' => ['[]', 'not an object'],
    'nested empty array where an object is required' => ['{"zusammenfassung":"x","aufgaben":[],"details":[]}', '$.details must be of type object'],
    'nested empty object where an array is required' => ['{"zusammenfassung":"x","aufgaben":{}}', '$.aufgaben must be of type array'],
    'required field inside a nested empty object' => ['{"zusammenfassung":"x","aufgaben":[],"details":{}}', '$.details.titel is required'],
    'missing field' => ['{"zusammenfassung":"x"}', '$.aufgaben is required'],
    'wrong type' => ['{"zusammenfassung":"x","aufgaben":"eine"}', '$.aufgaben must be of type array'],
]);

it('fails with a clear message when the configured schema is not JSON', function (): void {
    $fake = fakeAi('{}');

    $result = aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'p', 'output_mode' => 'json', 'json_schema' => '{not json',
    ]);

    expect($result->isFailed())->toBeTrue()->and($result->error)->toContain('schema')
        ->and($fake->timeout)->toBeNull();
});

it('accepts an empty object reply when the schema demands nothing', function (): void {
    fakeAi('{}');

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'p', 'output_mode' => 'json']);

    expect($result->isSuccess())->toBeTrue()->and($result->output['json'])->toBe([]);
});

it('rejects a schema that parses but cannot check anything', function (string $schema): void {
    $fake = fakeAi('{}');

    $result = aiAction()->execute(AutomationContext::make([]), [
        'prompt' => 'p', 'output_mode' => 'json', 'json_schema' => $schema,
    ]);

    expect($result->isFailed())->toBeTrue()->and($result->error)->toContain('schema')
        ->and($fake->timeout)->toBeNull();
})->with([
    'list instead of object' => ['[]'],
    'scalar' => ['"object"'],
    'unknown type' => ['{"type":"banana"}'],
    'properties as list' => ['{"type":"object","properties":["a"]}'],
    'property not an object' => ['{"type":"object","properties":{"a":"string"}}'],
    'nested bad type' => ['{"type":"object","properties":{"a":{"type":"strnig"}}}'],
    'required not names' => ['{"type":"object","required":"a"}'],
    'items as list' => ['{"type":"array","items":[{"type":"string"}]}'],
    'empty enum' => ['{"enum":[]}'],
]);

it('exposes the schema fields as output variables in JSON mode only', function (): void {
    expect(AiGenerateAction::outputSchemaFor(['output_mode' => 'json', 'json_schema' => reviewSchema()])['json'])
        ->toBe(['zusammenfassung' => 'string', 'aufgaben' => 'array', 'details' => 'object'])
        ->and(AiGenerateAction::outputSchemaFor([]))->not->toHaveKey('json');
});
