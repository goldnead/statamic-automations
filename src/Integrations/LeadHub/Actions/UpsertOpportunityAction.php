<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class UpsertOpportunityAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.create_or_update_opportunity';
    }

    public static function label(): string
    {
        return 'Create or Update Opportunity';
    }

    public static function description(): ?string
    {
        return 'Creates or updates a LeadHub pipeline opportunity for a lead.';
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
            ['handle' => 'lead_id', 'label' => 'Lead', 'type' => 'data_reference', 'source' => 'lead', 'required' => true],
            ['handle' => 'pipeline', 'label' => 'Pipeline (slug or id)', 'type' => 'text', 'required' => true],
            ['handle' => 'stage_slug', 'label' => 'Stage slug', 'type' => 'text', 'required' => false],
            ['handle' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => false],
            ['handle' => 'value_estimate', 'label' => 'Value estimate', 'type' => 'text', 'required' => false],
            ['handle' => 'confidence', 'label' => 'Confidence (0-100)', 'type' => 'text', 'required' => false],
            ['handle' => 'source_type', 'label' => 'Source type', 'type' => 'text', 'required' => false],
            ['handle' => 'source_id', 'label' => 'Source id', 'type' => 'text', 'required' => false],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $leadId = $config['lead_id'] ?? $context->get('lead.id');
        $pipeline = $config['pipeline'] ?? null;

        if (empty($leadId) || empty($pipeline)) {
            return ActionResult::failed('Both lead reference and pipeline are required.');
        }

        $attributes = array_filter([
            'stage_slug' => $config['stage_slug'] ?? null,
            'title' => $config['title'] ?? null,
            'value_estimate' => $config['value_estimate'] ?? null,
            'confidence' => $config['confidence'] ?? null,
            'source_type' => $config['source_type'] ?? null,
            'source_id' => $config['source_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId, 'pipeline' => $pipeline, 'opportunity' => $attributes],
                'note' => 'Test mode — LeadHub opportunity skipped.',
            ]);
        }

        $result = $this->adapter->upsertOpportunity((string) $leadId, (string) $pipeline, $attributes);

        return $result['ok']
            ? ActionResult::success(['opportunity' => $result['result'] ?? $attributes])
            : ActionResult::failed($result['error'] ?? 'LeadHub upsert-opportunity failed.');
    }
}
