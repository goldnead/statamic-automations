<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Support\SecretStore;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;

it('resolves a secret token from the store, not the context', function () {
    config()->set('automations.secrets', ['stripe_key' => 'sk_live_123']);

    $resolved = app(TokenResolver::class)
        ->resolveString('{{ secret.stripe_key }}', AutomationContext::make([]));

    expect($resolved)->toBe('sk_live_123');
});

it('returns null for an unknown secret', function () {
    config()->set('automations.secrets', []);
    expect(app(SecretStore::class)->get('nope'))->toBeNull();
});

it('exposes secret names but never values', function () {
    config()->set('automations.secrets', ['a' => '1', 'b' => '2']);
    expect(app(SecretStore::class)->names())->toBe(['a', 'b']);
});

it('embeds a secret token mid-string', function () {
    config()->set('automations.secrets', ['token' => 'XYZ']);

    $resolved = app(TokenResolver::class)
        ->resolveString('Bearer {{ secret.token }}', AutomationContext::make([]));

    expect($resolved)->toBe('Bearer XYZ');
});

it('ships the new scheduled, webhook and AI templates', function () {
    $handles = collect(app(TemplateRegistry::class)->all())->pluck('handle')->all();

    expect($handles)->toContain('scheduled_digest');
    expect($handles)->toContain('inbound_webhook_to_entry');
    expect($handles)->toContain('ai_triage_inquiry');
});
