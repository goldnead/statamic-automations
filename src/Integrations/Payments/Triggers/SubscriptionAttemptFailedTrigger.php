<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * A charge of a running agreement failed, and this is the n-th in a row.
 *
 * Claimed once per failed payment by the payments addon, so this fires once
 * per failure rather than once per webhook delivery. `attempt` starts at 1 and
 * a paid cycle resets it. The filter lets one flow answer the first failure
 * gently and another the third one firmly.
 *
 * Counted per payment: on Stripe the provider's own retries of one invoice
 * stay one attempt.
 */
class SubscriptionAttemptFailedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_attempt_failed';
    }

    public static function label(): string
    {
        return 'Subscription Charge Failed';
    }

    public static function description(): ?string
    {
        return 'Triggered once per failed charge of a running subscription, with the number of failures in a row.';
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
        return array_merge(self::productFilterSchema(), [
            [
                'handle' => 'attempt',
                'label' => 'Only failure number',
                'type' => 'number',
                'required' => false,
                'help' => 'For example 3 for the third failure in a row. Leave empty for every failure.',
            ],
        ]);
    }

    public static function outputSchema(): array
    {
        return [
            'subscription' => self::subscriptionOutputSchema(),
            'payment' => self::paymentOutputSchema(),
            'attempt' => 'integer',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        if (! $this->matchesProduct($event, $config)) {
            return false;
        }

        $wanted = $this->intOf($config['attempt'] ?? null);

        return $wanted === null || $wanted < 1
            || $this->intOf($this->propertyOf($event, 'attempt')) === $wanted;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'subscription' => $this->subscriptionOf($event),
            'payment' => $this->paymentOf($event),
            'attempt' => $this->intOf($this->propertyOf($event, 'attempt')),
        ]);
    }
}
