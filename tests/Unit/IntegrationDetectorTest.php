<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Tests\TestCase;

class IntegrationDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IntegrationDetector::flush();
    }

    public function test_returns_false_when_no_facade_classes_exist(): void
    {
        config()->set('automations.integrations.webhook_manager.detect', [
            'NotARealClass\\WebhookManager',
        ]);
        config()->set('automations.integrations.leadhub.detect', [
            'NotARealClass\\LeadHub',
        ]);

        $detector = new IntegrationDetector;

        $this->assertFalse($detector->hasWebhookManager());
        $this->assertFalse($detector->hasLeadHub());
    }

    public function test_snapshot_returns_both_flags(): void
    {
        config()->set('automations.integrations.webhook_manager.detect', ['NotReal']);
        config()->set('automations.integrations.leadhub.detect', ['NotReal']);

        $detector = new IntegrationDetector;
        $snapshot = $detector->snapshot();

        $this->assertArrayHasKey('webhook_manager', $snapshot);
        $this->assertArrayHasKey('leadhub', $snapshot);
        $this->assertFalse($snapshot['webhook_manager']);
        $this->assertFalse($snapshot['leadhub']);
    }

    public function test_leadhub_candidates_use_the_real_psr4_casing(): void
    {
        // The LeadHub addon's PSR-4 namespace is Goldnead\Leadhub
        // (lowercase "hub"). Detection must probe exactly that casing —
        // a wrong-cased FQCN would silently never match via the autoloader.
        $method = new \ReflectionMethod(IntegrationDetector::class, 'leadHubClasses');
        $candidates = $method->invoke(new IntegrationDetector);

        $this->assertContains('Goldnead\\Leadhub\\Facades\\LeadHub', $candidates);
        $this->assertContains('Goldnead\\Leadhub\\LeadHubManager', $candidates);
        // Legacy casings must be gone — they point at classes that never existed.
        $this->assertNotContains('Goldnead\\LeadHub\\Facades\\LeadHub', $candidates);
        $this->assertNotContains('Goldnead\\LeadHub\\LeadHub', $candidates);
    }

    public function test_detection_is_cached(): void
    {
        config()->set('automations.integrations.webhook_manager.detect', ['NotReal']);

        $detector = new IntegrationDetector;
        $detector->hasWebhookManager(); // primes the cache

        config()->set('automations.integrations.webhook_manager.detect', [self::class]);
        // Cached result should still be false even though we now have a real class.
        $this->assertFalse($detector->hasWebhookManager());

        IntegrationDetector::flush();
        $this->assertTrue($detector->hasWebhookManager());
    }
}
