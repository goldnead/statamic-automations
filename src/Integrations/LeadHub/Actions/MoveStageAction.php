<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class MoveStageAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.move_stage';
    }

    public static function label(): string
    {
        return 'Move Opportunity Stage';
    }

    public static function description(): ?string
    {
        return 'Moves a LeadHub opportunity to a different pipeline stage.';
    }

    public static function group(): string
    {
        return 'LeadHub';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            ['handle' => 'opportunity_id', 'label' => 'Opportunity ID', 'type' => 'text', 'required' => true],
            ['handle' => 'stage', 'label' => 'Stage (slug or id)', 'type' => 'text', 'required' => true],
            ['handle' => 'note', 'label' => 'Note', 'type' => 'textarea', 'required' => false],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $opportunityId = $config['opportunity_id'] ?? $context->get('opportunity.id');
        $stage = $config['stage'] ?? null;

        if (empty($opportunityId) || empty($stage)) {
            return ActionResult::failed('Both opportunity reference and target stage are required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['opportunity_id' => $opportunityId, 'stage' => $stage],
                'note' => 'Test mode — LeadHub stage move skipped.',
            ]);
        }

        $result = $this->adapter->moveStage((string) $opportunityId, (string) $stage, $config['note'] ?? null);

        return $result['ok']
            ? ActionResult::success(['opportunity' => $result['result'] ?? null])
            : ActionResult::failed($result['error'] ?? 'LeadHub move-stage failed.');
    }
}
