<?php

use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;

/**
 * Bug A2: the CP destination picker for the `webhook_manager.send` action
 * showed "Install Webhook Manager to use this option" even when Webhook
 * Manager was installed, because the adapter queried a `destinations()`
 * method on the facade root that does not exist. The real surface is the
 * OutboundWebhookRepositoryInterface::all() collection of OutboundWebhook
 * models (handle / name).
 */

function bindFakeOutboundRepository(array $hooks): string
{
    $interface = config(
        'automations.integrations.webhook_manager.outbound_repository',
        'Goldnead\\WebhookManager\\Contracts\\Repositories\\OutboundWebhookRepositoryInterface'
    );

    $collection = collect($hooks)->map(fn ($h) => (object) $h);

    app()->bind($interface, fn () => new class($collection)
    {
        public function __construct(private \Illuminate\Support\Collection $hooks) {}

        public function all(): \Illuminate\Support\Collection
        {
            return $this->hooks;
        }

        public function findByHandle(string $handle): ?object
        {
            return $this->hooks->firstWhere('handle', $handle);
        }
    });

    return $interface;
}

it('lists outbound webhook destinations from the webhook-manager repository', function () {
    bindFakeOutboundRepository([
        ['handle' => 'notify-crm', 'name' => 'Notify CRM'],
        ['handle' => 'ping-slack', 'name' => 'Ping Slack'],
    ]);

    $adapter = app(WebhookManagerAdapter::class);

    expect($adapter->destinations())->toBe([
        ['value' => 'notify-crm', 'label' => 'Notify CRM'],
        ['value' => 'ping-slack', 'label' => 'Ping Slack'],
    ]);
});

it('returns an empty destination list when Webhook Manager is genuinely absent', function () {
    $adapter = app(WebhookManagerAdapter::class);

    expect($adapter->destinations())->toBe([]);
});
