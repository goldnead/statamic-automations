<?php

use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;

it('resolves the template registry as a singleton', function (): void {
    expect(app(TemplateRegistry::class))->toBe(app(TemplateRegistry::class));
});

it('registers external templates through the facade', function (): void {
    Automations::template([
        'handle' => 'marketing_welcome_series',
        'name' => 'Welcome Series',
        'description' => 'Greet new newsletter subscribers.',
        'requires' => ['marketing'],
        'nodes' => [
            ['node_key' => 'trigger', 'type' => 'marketing.subscribed', 'position_x' => 0, 'position_y' => 0],
            ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 250, 'position_y' => 0, 'config' => [
                'to' => '{{ subscriber.email }}',
                'subject' => 'Welcome!',
                'body' => 'Hi {{ subscriber.first_name }}',
            ]],
        ],
        'edges' => [
            ['from_node_key' => 'trigger', 'to_node_key' => 'email'],
        ],
    ]);

    $registry = app(TemplateRegistry::class);

    expect($registry->get('marketing_welcome_series'))->not->toBeNull()
        ->and($registry->get('marketing_welcome_series')['requires'])->toBe(['marketing'])
        ->and(collect($registry->all())->pluck('handle'))->toContain('marketing_welcome_series');

    // Built-ins are still present.
    expect($registry->get('new_lead_notification'))->not->toBeNull();
});

it('lets a custom template replace a built-in with the same handle', function (): void {
    app(TemplateRegistry::class)->register([
        'handle' => 'new_lead_notification',
        'name' => 'Overridden',
        'nodes' => [['node_key' => 'trigger', 'type' => 'manual', 'position_x' => 0, 'position_y' => 0]],
        'edges' => [],
    ]);

    $registry = app(TemplateRegistry::class);

    expect($registry->get('new_lead_notification')['name'])->toBe('Overridden')
        ->and(collect($registry->all())->pluck('handle')->filter(fn ($h) => $h === 'new_lead_notification'))->toHaveCount(1);
});

it('rejects templates without a handle or nodes', function (): void {
    app(TemplateRegistry::class)->register(['name' => 'Broken']);
})->throws(InvalidArgumentException::class);
