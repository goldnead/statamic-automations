<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class ChangeLeadStatusAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter)
    {
    }

    public static function handle(): string
    {
        return 'leadhub.change_status';
    }

    public static function label(): string
    {
        return 'Change Lead Status';
    }

    public static function description(): ?string
    {
        return 'Updates the status of a LeadHub lead.';
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
                'help' => 'A reference to the lead. Defaults to {{ lead.id }} when available.',
            ],
            [
                'handle' => 'status',
                'label' => 'New status',
                'type' => 'select',
                'options_source' => 'leadhub.statuses',
                'required' => true,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $leadId = $config['lead_id'] ?? $context->get('lead.id');
        $status = $config['status'] ?? null;

        if (empty($leadId)) {
            return ActionResult::failed('Lead reference is required.');
        }
        if (empty($status)) {
            return ActionResult::failed('New status is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId, 'status' => $status],
                'note' => 'Test mode — LeadHub status change skipped.',
            ]);
        }

        $result = $this->adapter->changeStatus((string) $leadId, (string) $status);

        return $result['ok']
            ? ActionResult::success(['lead_id' => $leadId, 'status' => $status])
            : ActionResult::failed($result['error'] ?? 'LeadHub change-status failed.');
    }
}
