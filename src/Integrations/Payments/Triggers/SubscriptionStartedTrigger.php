<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * An agreement now exists, and the first payment is behind it.
 *
 * The right place for "welcome, here is what happens next". The wrong place for
 * granting access: that already happened on `payments.paid`, per payment, and it
 * will happen again on every cycle. A flow that grants here as well grants
 * twice on the first cycle.
 *
 * Both models are in the context because the first cycle is the one case where
 * the payment and the subscription are different facts a step may need at once:
 * the invoice belongs to the payment, the cancellation link to the subscription.
 */
class SubscriptionStartedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_started';
    }

    public static function label(): string
    {
        return 'Subscription Started';
    }

    public static function description(): ?string
    {
        return 'Triggered when a subscription is confirmed by the provider and its first cycle is paid.';
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
