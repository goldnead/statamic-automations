<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class CreateTaskAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.create_task';
    }

    public static function label(): string
    {
        return 'Create Task';
    }

    public static function description(): ?string
    {
        return 'Creates a LeadHub task, optionally attached to a lead.';
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
            ['handle' => 'lead_id', 'label' => 'Lead', 'type' => 'data_reference', 'source' => 'lead', 'required' => false],
            ['handle' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'help' => 'Supports tokens.'],
            ['handle' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
            ['handle' => 'priority', 'label' => 'Priority', 'type' => 'select', 'options' => ['low', 'normal', 'high'], 'required' => false],
            ['handle' => 'due_at', 'label' => 'Due at', 'type' => 'text', 'required' => false, 'help' => 'A parseable date/time.'],
            ['handle' => 'assignee_id', 'label' => 'Assignee ID', 'type' => 'text', 'required' => false],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $title = $config['title'] ?? null;

        // Static configuration — a task without a title is misconfigured, and
        // a test run has to say so. The optional lead reference below is never
        // required, so it needs no deferral.
        // See ActionResult::missingDataReference() for where that line runs.
        if (empty($title)) {
            return ActionResult::failed('A task title is required.');
        }

        $leadId = $config['lead_id'] ?? $context->get('lead.id');

        $attributes = array_filter([
            'title' => $title,
            'description' => $config['description'] ?? null,
            'priority' => $config['priority'] ?? null,
            'due_at' => $config['due_at'] ?? null,
            'assignee_id' => $config['assignee_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['lead_id' => $leadId, 'task' => $attributes],
                'note' => 'Test mode — LeadHub task skipped.',
            ]);
        }

        $result = $this->adapter->createTask($attributes, $leadId ? (string) $leadId : null);

        return $result['ok']
            ? ActionResult::success(['task' => $result['result'] ?? $attributes])
            : ActionResult::failed($result['error'] ?? 'LeadHub create-task failed.');
    }
}
