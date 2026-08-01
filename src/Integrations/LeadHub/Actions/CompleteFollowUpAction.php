<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class CompleteFollowUpAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.complete_follow_up';
    }

    public static function label(): string
    {
        return 'Complete Follow-up';
    }

    public static function description(): ?string
    {
        return 'Marks a follow-up on a LeadHub lead as complete.';
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
            [
                'handle' => 'lead_id',
                'label' => 'Lead',
                'type' => 'data_reference',
                'source' => 'lead',
                'required' => true,
            ],
            [
                'handle' => 'follow_up_id',
                'label' => 'Follow-up id',
                'type' => 'text',
                'help' => 'Optional — if empty, the most recent open follow-up is completed.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $leadId = $config['lead_id'] ?? $context->get('lead.id');
        $followUpId = $config['follow_up_id'] ?? null;

        // This action has no static configuration to validate; the lead
        // reference is checked *after* the test-mode branch on purpose:
        // see ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId, 'follow_up_id' => $followUpId],
                'note' => 'Test mode — LeadHub follow-up completion skipped.',
            ]);
        }

        if (empty($leadId)) {
            return ActionResult::missingDataReference('lead_id', 'Lead', '{{ lead.id }}');
        }

        $result = $this->adapter->completeFollowUp((string) $leadId, $followUpId ? (string) $followUpId : null);

        return $result['ok']
            ? ActionResult::success(['lead_id' => $leadId, 'follow_up_id' => $followUpId])
            : ActionResult::failed($result['error'] ?? 'LeadHub follow-up completion failed.');
    }
}
