<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * Somebody started a checkout and did not finish it.
 *
 * Reported once per payment, claimed in the payments addon's own column, so a
 * reminder goes out once however often the sweep runs. A payment that arrives
 * afterwards clears that claim — the sequence should then end on
 * `payments.paid`, which is the honest signal that they bought it.
 *
 * **Before you build a mail step on this:** the address on an unfinished
 * checkout was given to complete a purchase, not to receive advertising.
 * Whether a reminder may go out is a question of consent, and the suppression
 * list belongs in front of the send either way.
 */
class CheckoutAbandonedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.checkout_abandoned';
    }

    public static function label(): string
    {
        return 'Checkout Abandoned';
    }

    public static function description(): ?string
    {
        return 'Triggered when a checkout was started and left unpaid past the waiting period.';
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
            'payment' => $this->paymentOf($event),
        ]);
    }
}
