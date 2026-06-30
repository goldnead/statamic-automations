<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions\CreateTaskAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions\MoveStageAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions\UpsertOpportunityAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Tests\TestCase;

class LeadHubCrmActionsTest extends TestCase
{
    public function test_new_actions_expose_expected_handles(): void
    {
        $this->assertSame('leadhub.create_task', CreateTaskAction::handle());
        $this->assertSame('leadhub.move_stage', MoveStageAction::handle());
        $this->assertSame('leadhub.create_or_update_opportunity', UpsertOpportunityAction::handle());

        foreach ([CreateTaskAction::class, MoveStageAction::class, UpsertOpportunityAction::class] as $class) {
            $this->assertSame('LeadHub', $class::group());
            $this->assertTrue($class::supportsTestMode());
            $this->assertNotEmpty($class::schema());
        }
    }

    public function test_create_task_returns_preview_in_test_mode(): void
    {
        $action = new CreateTaskAction(new LeadHubAdapter());
        $context = AutomationContext::make(['lead' => ['id' => '42']], testMode: true);

        $result = $action->execute($context, ['title' => 'Call back', 'priority' => 'high']);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('preview', $result->output);
    }

    public function test_create_task_requires_a_title(): void
    {
        $action = new CreateTaskAction(new LeadHubAdapter());
        $context = AutomationContext::make([], testMode: true);

        $result = $action->execute($context, []);

        $this->assertFalse($result->isSuccess());
    }

    public function test_detector_recognises_the_lowercase_leadhub_namespace(): void
    {
        IntegrationDetector::flush();
        // The real addon facade is Goldnead\Leadhub\Facades\LeadHub; simulate it
        // being present via the configurable detect list using this test class.
        config()->set('automations.integrations.leadhub.detect', [self::class]);

        $this->assertTrue((new IntegrationDetector())->hasLeadHub());

        IntegrationDetector::flush();
    }
}
