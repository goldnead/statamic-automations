<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * It ran to its end on its own: a payment plan that paid its last instalment.
 *
 * The counterpart to `payments.subscription_cancelled`, kept apart on purpose.
 * See that trigger for why. This is the one to hang "you have paid it off" on.
 */
class SubscriptionEndedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_ended';
    }

    public static function label(): string
    {
        return 'Subscription Ended';
    }

    public static function description(): ?string
    {
        return 'Triggered when a subscription reaches its own end, for example a payment plan that is paid off.';
    }

    public static function group(): string
    {
        return 'Payments';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::productFilterSchema();
    }

    public static function outputSchema(): array
    {
        return [
            'subscription' => self::subscriptionOutputSchema(),
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->matchesProduct($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'subscription' => $this->subscriptionOf($event),
        ]);
    }
}
