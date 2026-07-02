<?php

namespace Goldnead\StatamicAutomations\Integrations\Marketing\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Marketing\MarketingAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class UnsubscribeFromListAction implements AutomationAction
{
    public function __construct(protected MarketingAdapter $adapter)
    {
    }

    public static function handle(): string
    {
        return 'marketing.unsubscribe';
    }

    public static function label(): string
    {
        return 'Unsubscribe from Mailing List';
    }

    public static function description(): ?string
    {
        return 'Removes an email address from a marketing mailing list.';
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
            ],
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => true,
                'help' => 'Supports tokens, e.g. {{ subscriber.email }}.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $list = (string) ($config['list'] ?? '');
        $email = (string) ($config['email'] ?? '');

        if (! $list || ! $email) {
            return ActionResult::failed('marketing.unsubscribe requires both "list" and "email".');
        }

        if ($context->isTestMode()) {
            return ActionResult::success(['preview' => true, 'list' => $list, 'email' => $email]);
        }

        try {
            $result = $this->adapter->unsubscribe($list, $email);
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage());
        }

        if ($result === null) {
            return ActionResult::skipped("No subscription for [{$email}] on list [{$list}].");
        }

        return ActionResult::success([
            'subscription_uuid' => $result['uuid'],
            'status' => $result['status'],
            'list' => $list,
            'email' => $email,
        ]);
    }
}
