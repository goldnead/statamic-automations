<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Tests\TestCase;

class TokenResolverTest extends TestCase
{
    private function resolver(): TokenResolver
    {
        return new TokenResolver();
    }

    public function test_resolves_simple_token(): void
    {
        $context = AutomationContext::make([
            'lead' => ['email' => 'jane@example.com'],
        ]);

        $resolved = $this->resolver()->resolve('Hello {{ lead.email }}', $context);

        $this->assertSame('Hello jane@example.com', $resolved);
    }

    public function test_resolves_nested_tokens(): void
    {
        $context = AutomationContext::make([
            'webhook' => ['payload' => ['customer' => ['name' => 'ACME']]],
        ]);

        $resolved = $this->resolver()->resolve(
            'Customer: {{ webhook.payload.customer.name }}',
            $context,
        );

        $this->assertSame('Customer: ACME', $resolved);
    }

    public function test_missing_tokens_become_empty_strings(): void
    {
        $context = AutomationContext::make([]);

        $resolved = $this->resolver()->resolve('Hello {{ lead.full_name }}!', $context);

        $this->assertSame('Hello !', $resolved);
    }

    public function test_walks_nested_arrays(): void
    {
        $context = AutomationContext::make(['form' => ['email' => 'a@b.de']]);

        $resolved = $this->resolver()->resolve([
            'subject' => 'New: {{ form.email }}',
            'body' => ['greeting' => 'Hi {{ form.email }}'],
        ], $context);

        $this->assertSame('New: a@b.de', $resolved['subject']);
        $this->assertSame('Hi a@b.de', $resolved['body']['greeting']);
    }

    public function test_single_token_returns_structured_value(): void
    {
        $context = AutomationContext::make(['data' => ['list' => [1, 2, 3]]]);

        $resolved = $this->resolver()->resolve('{{ data.list }}', $context);

        $this->assertSame([1, 2, 3], $resolved);
    }

    public function test_redacts_sensitive_keys(): void
    {
        $resolver = $this->resolver();

        $redacted = $resolver->redact([
            'lead' => [
                'email' => 'jane@example.com',
                'password' => 'super-secret',
                'api_key' => 'sk_live_abc',
            ],
            'token' => 'tk_xyz',
            'safe' => 'visible',
        ], ['password', 'token', 'api_key']);

        $this->assertSame('jane@example.com', $redacted['lead']['email']);
        $this->assertSame('***REDACTED***', $redacted['lead']['password']);
        $this->assertSame('***REDACTED***', $redacted['lead']['api_key']);
        $this->assertSame('***REDACTED***', $redacted['token']);
        $this->assertSame('visible', $redacted['safe']);
    }
}
