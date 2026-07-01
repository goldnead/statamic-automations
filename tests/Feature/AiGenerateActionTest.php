<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\AiGenerateAction;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('automations.features.ai_action_requires_pro', false);
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

it('is blocked when Pro gating is on and no license is present', function (): void {
    config()->set('automations.features.ai_action_requires_pro', true);
    config()->set('automations.license.key', '');
    Http::fake();

    $result = aiAction()->execute(AutomationContext::make([]), ['prompt' => 'hi']);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('Pro');
    Http::assertNothingSent();
});
