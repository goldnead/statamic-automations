<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * The next charge of an agreement is close.
 *
 * Once per agreement and charge date; the payments addon claims it before it
 * announces, so an overlapping scheduler run starts nothing twice here either.
 * `days_before` is the setting it went out under, not a count computed now.
 */
class SubscriptionPaymentUpcomingTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_payment_upcoming';
    }

    public static function label(): string
    {
        return 'Subscription Payment Upcoming';
    }

    public static function description(): ?string
    {
        return 'Triggered a set number of days before a subscription is charged again.';
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
            'due_at' => 'string',
            'days_before' => 'integer',
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
            'due_at' => $this->dateOf($this->propertyOf($event, 'dueAt')),
            'days_before' => $this->intOf($this->propertyOf($event, 'daysBefore')),
        ]);
    }
}
