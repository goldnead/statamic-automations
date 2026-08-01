<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Licensing\LicenseManager;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class LicenseManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automations.license.key', '');
        config()->set('automations.license.mode', 'config');
        config()->set('automations.license.allowed_keys', []);
        config()->set('automations.license.endpoint', '');

        Cache::flush();
    }

    public function test_status_no_key_when_unset(): void
    {
        $manager = new LicenseManager;

        $this->assertSame(LicenseManager::STATUS_NO_KEY, $manager->status()['status']);
        $this->assertFalse($manager->isPro());
    }

    public function test_status_invalid_when_key_not_in_allowed_list(): void
    {
        config()->set('automations.license.key', 'WRONG-KEY');
        config()->set('automations.license.allowed_keys', ['VALID-KEY']);

        $manager = new LicenseManager;
        $this->assertSame(LicenseManager::STATUS_INVALID, $manager->status()['status']);
        $this->assertFalse($manager->isPro());
    }

    public function test_status_valid_when_key_in_allowed_list(): void
    {
        config()->set('automations.license.key', 'VALID-KEY');
        config()->set('automations.license.allowed_keys', ['VALID-KEY']);

        $manager = new LicenseManager;
        $this->assertSame(LicenseManager::STATUS_VALID, $manager->status()['status']);
        $this->assertTrue($manager->isPro());
    }

    public function test_gates_returns_true_when_pro_gating_is_disabled(): void
    {
        config()->set('automations.features.custom_actions_requires_pro', false);

        $manager = new LicenseManager;
        $this->assertTrue($manager->gates('custom_actions'));
    }

    public function test_gates_returns_false_for_invalid_license(): void
    {
        config()->set('automations.features.custom_actions_requires_pro', true);
        config()->set('automations.license.key', 'WRONG-KEY');
        config()->set('automations.license.allowed_keys', ['VALID-KEY']);

        $manager = new LicenseManager;
        $this->assertFalse($manager->gates('custom_actions'));
    }

    public function test_gates_returns_true_for_valid_license(): void
    {
        config()->set('automations.features.custom_actions_requires_pro', true);
        config()->set('automations.license.key', 'VALID-KEY');
        config()->set('automations.license.allowed_keys', ['VALID-KEY']);

        $manager = new LicenseManager;
        $this->assertTrue($manager->gates('custom_actions'));
    }

    public function test_unknown_features_are_always_gated_to_true(): void
    {
        $manager = new LicenseManager;
        $this->assertTrue($manager->gates('made_up_feature'));
    }
}
