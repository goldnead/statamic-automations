<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * An agreement was paused. Nothing is charged until it resumes.
 *
 * Fired after the provider confirmed. `resumes_at` is empty when the pause
 * runs until somebody ends it, which is what a "we will write before it
 * starts again" mail has to check before it promises a date.
 */
class SubscriptionPausedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_paused';
    }

    public static function label(): string
    {
        return 'Subscription Paused';
    }

    public static function description(): ?string
    {
        return 'Triggered when a subscription is paused, from the Control Panel or by the customer in the portal.';
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
            'resumes_at' => 'string',
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
            'resumes_at' => $this->dateOf($this->propertyOf($event, 'resumesAt')),
            'by' => $this->stringOf($this->propertyOf($event, 'by')),
        ]);
    }
}
