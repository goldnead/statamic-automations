<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * A payment plan received its last instalment. Everything is paid.
 *
 * `payments.subscription_ended` fires at the same moment, for every ending.
 * This one fires only for the good one, so a thank-you flow does not have to
 * read a status to be sure it is not thanking somebody who stopped paying.
 */
class SubscriptionPlanCompletedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_plan_completed';
    }

    public static function label(): string
    {
        return 'Payment Plan Completed';
    }

    public static function description(): ?string
    {
        return 'Triggered once when the last instalment of a payment plan is paid. payments.subscription_ended fires at the same moment; use one of the two, not both.';
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
            'payment' => self::paymentOutputSchema(),
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
            'payment' => $this->paymentOf($event),
        ]);
    }
}
