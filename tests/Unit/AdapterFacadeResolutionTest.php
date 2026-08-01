<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * Real Laravel facades proxy calls through __callStatic, so
 * method_exists() on the facade class returns false for every proxied
 * method. The adapters must resolve the facade root instance and probe
 * that instead — while still supporting plain classes with real static
 * methods (the historic test-fake style).
 */
class AdapterFacadeResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RealFacadeFakeLeadHubManager::$calls = [];
        Facade::clearResolvedInstances();

        $this->app->singleton('fake-leadhub-manager', fn () => new RealFacadeFakeLeadHubManager);
    }

    public function test_create_task_works_through_a_real_laravel_facade(): void
    {
        config()->set('automations.integrations.leadhub.facade', [RealLeadHubFacade::class]);

        $result = (new LeadHubAdapter)->createTask(['title' => 'Call back'], '42');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([['createTask', [['title' => 'Call back'], '42']]], RealFacadeFakeLeadHubManager::$calls);
    }

    public function test_add_tag_works_through_a_real_laravel_facade(): void
    {
        config()->set('automations.integrations.leadhub.facade', [RealLeadHubFacade::class]);

        $result = (new LeadHubAdapter)->addTag('42', 'vip');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([['addTag', ['42', 'vip']]], RealFacadeFakeLeadHubManager::$calls);
    }

    public function test_create_or_update_works_through_a_real_laravel_facade(): void
    {
        config()->set('automations.integrations.leadhub.facade', [RealLeadHubFacade::class]);

        $result = (new LeadHubAdapter)->createOrUpdate(['email' => 'x@example.com']);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertTrue($result['created']);
    }

    public function test_fetch_options_works_through_a_real_laravel_facade(): void
    {
        config()->set('automations.integrations.leadhub.facade', [RealLeadHubFacade::class]);

        $tags = (new LeadHubAdapter)->tags();

        $this->assertSame([['value' => 'vip', 'label' => 'VIP']], $tags);
    }

    public function test_missing_methods_on_the_facade_root_still_error_cleanly(): void
    {
        config()->set('automations.integrations.leadhub.facade', [RealLeadHubFacade::class]);

        $result = (new LeadHubAdapter)->moveStage('opp-1', 'won');

        $this->assertFalse($result['ok']);
        $this->assertSame('LeadHub facade does not implement moveStage().', $result['error']);
    }

    public function test_plain_class_with_real_static_methods_keeps_working(): void
    {
        config()->set('automations.integrations.leadhub.facade', [StaticFakeLeadHub::class]);

        $result = (new LeadHubAdapter)->addTag('42', 'vip');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([['addTag', ['42', 'vip']]], StaticFakeLeadHub::$calls);
    }

    public function test_webhook_destinations_resolve_from_the_outbound_repository(): void
    {
        // Bug A2: destinations live in Webhook Manager's outbound webhook
        // repository, not on the facade root. Bind the repository the way the
        // addon's service provider does and confirm the adapter lists it.
        $interface = config('automations.integrations.webhook_manager.outbound_repository');
        $this->app->bind($interface, fn () => new RepositoryFakeOutboundWebhooks);

        $adapter = new WebhookManagerAdapter;

        $this->assertSame([['value' => 'crm', 'label' => 'CRM']], $adapter->destinations());
    }

    public function test_webhook_dispatch_degrades_gracefully_when_the_addon_is_absent(): void
    {
        // No repository binding and no Webhook Manager classes autoloaded in
        // the test env: dispatch must fail cleanly rather than fatal.
        $adapter = new WebhookManagerAdapter;

        $result = $adapter->dispatch('crm', ['a' => 1]);

        $this->assertFalse($result['ok']);
        $this->assertSame('Webhook Manager not installed.', $result['error']);
    }
}

class RepositoryFakeOutboundWebhooks
{
    public function all(): Collection
    {
        return collect([(object) ['handle' => 'crm', 'name' => 'CRM']]);
    }

    public function findByHandle(string $handle): ?object
    {
        return $this->all()->firstWhere('handle', $handle);
    }
}

class RealFacadeFakeLeadHubManager
{
    public static array $calls = [];

    public function createTask(array $attributes, ?string $leadId = null): array
    {
        static::$calls[] = ['createTask', [$attributes, $leadId]];

        return ['id' => 'task-1'];
    }

    public function addTag(string $id, string $tag): void
    {
        static::$calls[] = ['addTag', [$id, $tag]];
    }

    public function findByEmail(string $email): ?array
    {
        return null;
    }

    public function create(array $attributes): array
    {
        return ['id' => 'lead-1'] + $attributes;
    }

    public function tags(): array
    {
        return [['handle' => 'vip', 'label' => 'VIP']];
    }
}

class RealLeadHubFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fake-leadhub-manager';
    }
}

class StaticFakeLeadHub
{
    public static array $calls = [];

    public static function addTag(string $id, string $tag): void
    {
        static::$calls = [['addTag', [$id, $tag]]];
    }
}
