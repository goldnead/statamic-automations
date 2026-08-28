<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * Somebody paid for a subscription and did not get one.
 *
 * The money arrived, the payment row says so, and the agreement behind it does
 * not exist. This is the trigger a site uses to find out on the day rather than
 * on the day the customer writes in, so the flow behind it should reach a human:
 * an alert, not a customer mail.
 *
 * `reason` is the provider's or the addon's explanation, carried through as
 * text. There is no filter on it, deliberately — the point of this trigger is
 * that nothing gets swallowed.
 */
class SubscriptionStartFailedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_start_failed';
    }

    public static function label(): string
    {
        return 'Subscription Start Failed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a subscription payment succeeded but no subscription was created behind it.';
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
            'payment' => self::paymentOutputSchema(),
            'reason' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->matchesProduct($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $reason = $this->propertyOf($event, 'reason');

        return AutomationContext::make([
            'payment' => $this->paymentOf($event),
            'reason' => is_string($reason) ? $reason : null,
        ]);
    }
}
