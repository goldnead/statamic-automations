<?php

namespace Goldnead\StatamicAutomations\Integrations\Marketing\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Marketing\MarketingAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class SubscribeToListAction implements AutomationAction
{
    public function __construct(protected MarketingAdapter $adapter) {}

    public static function handle(): string
    {
        return 'marketing.subscribe';
    }

    public static function label(): string
    {
        return 'Subscribe to Mailing List';
    }

    public static function description(): ?string
    {
        return 'Adds an email address to a marketing mailing list (honours the list\'s double opt-in setting).';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'list',
                'label' => 'List handle',
                'type' => 'text',
                'required' => true,
                'help' => 'Handle of the marketing mailing list.',
            ],
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => true,
                'help' => 'Supports tokens, e.g. {{ lead.email }} or {{ submission.data.email }}.',
            ],
            [
                'handle' => 'first_name',
                'label' => 'First name',
                'type' => 'text',
                'required' => false,
            ],
            [
                'handle' => 'last_name',
                'label' => 'Last name',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $list = (string) ($config['list'] ?? '');
        $email = (string) ($config['email'] ?? '');

        if (! $list || ! $email) {
            return ActionResult::failed('marketing.subscribe requires both "list" and "email".');
        }

        if ($context->isTestMode()) {
            return ActionResult::success(['preview' => true, 'list' => $list, 'email' => $email]);
        }

        try {
            $result = $this->adapter->subscribe($list, $email, array_filter([
                'first_name' => $config['first_name'] ?? null,
                'last_name' => $config['last_name'] ?? null,
            ]));
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage());
        }

        if ($result === null) {
            return ActionResult::failed("Mailing list [{$list}] does not exist (or statamic-marketing is not installed).");
        }

        return ActionResult::success([
            'subscription_uuid' => $result['uuid'],
            'status' => $result['status'],
            'list' => $list,
            'email' => $email,
        ]);
    }
}
