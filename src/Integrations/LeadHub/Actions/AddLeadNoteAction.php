<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class AddLeadNoteAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.add_note';
    }

    public static function label(): string
    {
        return 'Add Lead Note';
    }

    public static function description(): ?string
    {
        return 'Adds a note to a LeadHub lead.';
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
                'handle' => 'body',
                'label' => 'Note body',
                'type' => 'textarea',
                'required' => true,
                'help' => 'Supports tokens.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $leadId = $config['lead_id'] ?? $context->get('lead.id');
        $body = $config['body'] ?? null;

        // Static configuration — an empty note body is misconfigured, and a
        // test run has to say so.
        if (empty($body)) {
            return ActionResult::failed('A note body is required.');
        }

        // The lead reference is checked *after* this branch on purpose:
        // see ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId, 'body' => $body],
                'note' => 'Test mode — LeadHub note skipped.',
            ]);
        }

        if (empty($leadId)) {
            return ActionResult::missingDataReference('lead_id', 'Lead', '{{ lead.id }}');
        }

        $result = $this->adapter->addNote((string) $leadId, (string) $body);

        return $result['ok']
            ? ActionResult::success(['lead_id' => $leadId, 'body' => $body])
            : ActionResult::failed($result['error'] ?? 'LeadHub add-note failed.');
    }
}
