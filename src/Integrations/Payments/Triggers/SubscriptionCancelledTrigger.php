<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * Somebody stopped it, and the provider agreed.
 *
 * Deliberately separate from `payments.subscription_ended`, and the separation
 * is the point: one is somebody leaving, the other is somebody finishing their
 * last instalment. A single flow across both would send "sorry to see you go"
 * to a customer who just paid everything they owed.
 *
 * Cancelled is not the same as over. `subscription.ended_at` says when access
 * actually stops; until then the agreement is cancelled and still running.
 */
class SubscriptionCancelledTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_cancelled';
    }

    public static function label(): string
    {
        return 'Subscription Cancelled';
    }

    public static function description(): ?string
    {
        return 'Triggered when the provider confirms that a subscription was cancelled.';
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
        ]);
    }
}
