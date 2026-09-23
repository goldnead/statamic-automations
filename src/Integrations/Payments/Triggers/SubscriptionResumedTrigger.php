<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * A paused agreement runs again.
 *
 * Nothing is charged at the moment of resuming; `subscription.next_payment_at`
 * is the first charge after the pause. `by` is `schedule` when the date set at
 * pausing was reached, which is the case where the customer did nothing and
 * may not remember.
 */
class SubscriptionResumedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_resumed';
    }

    public static function label(): string
    {
        return 'Subscription Resumed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a paused subscription runs again, by hand or on the date set when pausing.';
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
            'by' => 'string',
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
            'by' => $this->stringOf($this->propertyOf($event, 'by')),
        ]);
    }
}
