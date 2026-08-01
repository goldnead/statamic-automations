<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class CreateOrUpdateLeadAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.create_or_update_lead';
    }

    public static function label(): string
    {
        return 'Create or Update Lead';
    }

    public static function description(): ?string
    {
        return 'Creates a new LeadHub lead, or updates the existing lead matching the email.';
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
            ['handle' => 'email', 'label' => 'Email', 'type' => 'text', 'required' => true],
            ['handle' => 'first_name', 'label' => 'First name', 'type' => 'text'],
            ['handle' => 'last_name', 'label' => 'Last name', 'type' => 'text'],
            ['handle' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['handle' => 'company', 'label' => 'Company', 'type' => 'text'],
            [
                'handle' => 'status',
                'label' => 'Default status',
                'type' => 'select',
                'options_source' => 'leadhub.statuses',
            ],
            ['handle' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
            ['handle' => 'source', 'label' => 'Source label', 'type' => 'text'],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $email = $config['email'] ?? null;
        if (empty($email)) {
            return ActionResult::failed('Email is required to create or update a lead.');
        }

        $attributes = array_filter([
            'email' => $email,
            'first_name' => $config['first_name'] ?? null,
            'last_name' => $config['last_name'] ?? null,
            'phone' => $config['phone'] ?? null,
            'company' => $config['company'] ?? null,
            'status' => $config['status'] ?? null,
            'tags' => $config['tags'] ?? null,
            'source' => $config['source'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => $attributes,
                'note' => 'Test mode — LeadHub create/update skipped.',
            ]);
        }

        $result = $this->adapter->createOrUpdate($attributes);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'LeadHub returned an error.', ['attributes' => $attributes]);
        }

        // Persist the resulting lead into the context so subsequent
        // nodes can reference it via {{ lead.id }} etc.
        if (isset($result['lead']) && is_array($result['lead'])) {
            $context->set('lead', $result['lead']);
        }

        return ActionResult::success([
            'created' => $result['created'] ?? false,
            'lead' => $result['lead'] ?? null,
        ]);
    }
}
