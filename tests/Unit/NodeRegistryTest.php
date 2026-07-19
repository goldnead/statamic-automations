<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Tests\TestCase;

class NodeRegistryTest extends TestCase
{
    public function test_registers_built_in_triggers_and_actions(): void
    {
        $registry = app(NodeRegistry::class);

        $this->assertTrue($registry->has('manual'));
        $this->assertTrue($registry->has('form_submitted'));
        $this->assertTrue($registry->has('entry_published'));
        $this->assertTrue($registry->has('filter'));
        $this->assertTrue($registry->has('branch'));
        $this->assertTrue($registry->has('stop'));
        $this->assertTrue($registry->has('delay'));
        $this->assertTrue($registry->has('send_email'));
        $this->assertTrue($registry->has('send_webhook'));
        $this->assertTrue($registry->has('add_log_entry'));
    }

    public function test_describe_returns_schema(): void
    {
        $registry = app(NodeRegistry::class);
        $description = $registry->describe('send_email');

        $this->assertSame('send_email', $description['handle']);
        $this->assertSame('action', $description['kind']);
        $this->assertSame('Send Email', $description['label']);
        $this->assertNotEmpty($description['schema']);
    }

    public function test_can_register_custom_action(): void
    {
        Automations::action('test.custom', TestCustomAction::class);

        $registry = app(NodeRegistry::class);
        $this->assertTrue($registry->has('test.custom'));
        $this->assertSame('action', $registry->kind('test.custom'));

        $actions = app(ActionRegistry::class);
        $this->assertTrue($actions->has('test.custom'));
    }
}

class TestCustomAction implements AutomationAction
{
    public static function handle(): string { return 'test.custom'; }
    public static function label(): string { return 'Test Custom'; }
    public static function description(): ?string { return null; }
    public static function group(): string { return 'Custom'; }
    public static function supportsTestMode(): bool { return true; }
    public static function schema(): array { return []; }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ActionResult::success();
    }
}
