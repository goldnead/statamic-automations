<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * The card an agreement is charged against has expired.
 *
 * The next charge will fail unless a new card goes on file. Separate from the
 * warning before it, because "please update your card" and "your next payment
 * will fail" are two different mails.
 */
class SubscriptionCardExpiredTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_card_expired';
    }

    public static function label(): string
    {
        return 'Card Expired';
    }

    public static function description(): ?string
    {
        return 'Triggered when the card behind a subscription has expired and the next charge would fail.';
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
            'expired_at' => 'string',
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
            'expired_at' => $this->dateOf($this->propertyOf($event, 'expiredAt')),
        ]);
    }
}
