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
            // Declared as a data reference because that is what it is: the
            // action falls back to {{ opportunity.id }} from the run context.
            // Renders identically to `text` in the CP (both map to Input and
            // both carry the token inserter) — only the declaration changes,
            // so the schema now tells the truth about where the value comes
            // from. See ActionResult::missingDataReference().
            ['handle' => 'opportunity_id', 'label' => 'Opportunity', 'type' => 'data_reference', 'source' => 'opportunity', 'required' => true],
            ['handle' => 'stage', 'label' => 'Stage (slug or id)', 'type' => 'text', 'required' => true],
            ['handle' => 'note', 'label' => 'Note', 'type' => 'textarea', 'required' => false],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $opportunityId = $config['opportunity_id'] ?? $context->get('opportunity.id');
        $stage = $config['stage'] ?? null;

        // Static configuration — a node without a target stage is
        // misconfigured, and a test run has to say so.
        if (empty($stage)) {
            return ActionResult::failed('A target stage is required.');
        }

        // The opportunity reference is checked *after* this branch on purpose:
        // see ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['opportunity_id' => $opportunityId, 'stage' => $stage],
                'note' => 'Test mode — LeadHub stage move skipped.',
            ]);
        }

        if (empty($opportunityId)) {
            return ActionResult::missingDataReference('opportunity_id', 'Opportunity', '{{ opportunity.id }}');
        }

        $result = $this->adapter->moveStage((string) $opportunityId, (string) $stage, $config['note'] ?? null);

        return $result['ok']
            ? ActionResult::success(['opportunity' => $result['result'] ?? null])
            : ActionResult::failed($result['error'] ?? 'LeadHub move-stage failed.');
    }
}
