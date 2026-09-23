<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * The card an agreement is charged against expires soon.
 *
 * Only where the provider says when a card expires: Stripe for cards, Mollie
 * for credit card mandates. A SEPA mandate never fires this, so a flow built
 * on it is not a complete dunning answer on its own.
 */
class SubscriptionCardExpiringTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_card_expiring';
    }

    public static function label(): string
    {
        return 'Card Expiring';
    }

    public static function description(): ?string
    {
        return 'Triggered when the card behind a subscription expires soon. Stripe cards and Mollie credit card mandates only.';
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
            'expires_at' => 'string',
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
            'expires_at' => $this->dateOf($this->propertyOf($event, 'expiresAt')),
        ]);
    }
}
