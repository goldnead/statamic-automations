<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * A cycle was charged and paid.
 *
 * Fires once per cycle, claimed with a conditional update on the addon's side,
 * so a redelivered webhook does not announce the same month twice. A flow here
 * may therefore count: `subscription.times_charged` against
 * `subscription.times` is "instalment 3 of 12", and that is the difference
 * between "thank you" and "that was the last one".
 */
class SubscriptionRenewedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_renewed';
    }

    public static function label(): string
    {
        return 'Subscription Renewed';
    }

    public static function description(): ?string
    {
        return 'Triggered once per subscription cycle that is charged and paid.';
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
