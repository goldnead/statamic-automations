<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class CreateFollowUpAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.create_follow_up';
    }

    public static function label(): string
    {
        return 'Create Follow-up';
    }

    public static function description(): ?string
    {
        return 'Creates a follow-up entry for a LeadHub lead.';
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
                'handle' => 'due_in_days',
                'label' => 'Due in (days)',
                'type' => 'number',
                'default' => 3,
            ],
            [
                'handle' => 'due_at',
                'label' => 'Due at (ISO date)',
                'type' => 'text',
                'help' => 'If set, takes precedence over "Due in" days.',
            ],
            [
                'handle' => 'note',
                'label' => 'Note',
                'type' => 'textarea',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $leadId = $config['lead_id'] ?? $context->get('lead.id');

        $dueAt = $config['due_at'] ?? null;
        if (empty($dueAt)) {
            $days = max(0, (int) ($config['due_in_days'] ?? 0));
            $dueAt = now()->addDays($days)->toIso8601String();
        }

        $payload = array_filter([
            'due_at' => $dueAt,
            'note' => $config['note'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // This action has no static configuration to validate; the lead
        // reference is checked *after* the test-mode branch on purpose:
        // see ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId] + $payload,
                'note' => 'Test mode — LeadHub follow-up creation skipped.',
            ]);
        }

        if (empty($leadId)) {
            return ActionResult::missingDataReference('lead_id', 'Lead', '{{ lead.id }}');
        }

        $result = $this->adapter->createFollowUp((string) $leadId, $payload);

        return $result['ok']
            ? ActionResult::success(['lead_id' => $leadId, 'follow_up' => $result['result'] ?? null])
            : ActionResult::failed($result['error'] ?? 'LeadHub follow-up creation failed.');
    }
}
