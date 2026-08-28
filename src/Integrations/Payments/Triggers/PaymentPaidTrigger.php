<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * Money arrived.
 *
 * Fired from the fulfilment path, which means exactly once per payment even
 * when the provider delivers its webhook three times. A listener here is a
 * listener on the same claim that grants entitlements.
 */
class PaymentPaidTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.paid';
    }

    public static function label(): string
    {
        return 'Payment Paid';
    }

    public static function description(): ?string
    {
        return 'Triggered once a payment is confirmed paid by the provider.';
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
