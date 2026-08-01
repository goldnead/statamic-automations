<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class AddLeadTagAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.add_tag';
    }

    public static function label(): string
    {
        return 'Add Lead Tag';
    }

    public static function description(): ?string
    {
        return 'Adds a tag to a LeadHub lead.';
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
                'handle' => 'tag',
                'label' => 'Tag',
                'type' => 'select',
                'options_source' => 'leadhub.tags',
                'required' => true,
                'allow_custom' => true,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $leadId = $config['lead_id'] ?? $context->get('lead.id');
        $tag = $config['tag'] ?? null;

        // Static configuration — a node without a tag is misconfigured, and a
        // test run has to say so.
        if (empty($tag)) {
            return ActionResult::failed('A tag is required.');
        }

        // The lead reference is checked *after* this branch on purpose:
        // see ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId, 'tag' => $tag],
                'note' => 'Test mode — LeadHub tag addition skipped.',
            ]);
        }

        if (empty($leadId)) {
            return ActionResult::missingDataReference('lead_id', 'Lead', '{{ lead.id }}');
        }

        $result = $this->adapter->addTag((string) $leadId, (string) $tag);

        return $result['ok']
            ? ActionResult::success(['lead_id' => $leadId, 'tag' => $tag])
            : ActionResult::failed($result['error'] ?? 'LeadHub add-tag failed.');
    }
}
