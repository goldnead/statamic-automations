<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * The bank took the money back.
 *
 * Not a refund: a refund is a decision made here, a chargeback one made
 * against the site, with a fee and a deadline for evidence. The payments addon
 * already withdraws access on its own; what is left for a flow is telling a
 * person in time. `chargeback.reason` is the provider's own word and absent on
 * Mollie.
 */
class PaymentChargedBackTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.charged_back';
    }

    public static function label(): string
    {
        return 'Payment Charged Back';
    }

    public static function description(): ?string
    {
        return 'Triggered when the bank reverses a payment. Access is withdrawn by the payments addon; use this to alert a person.';
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
            'chargeback' => [
                'reference' => 'string',
                'amount_cent' => 'integer',
                'reason' => 'string',
            ],
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
            'chargeback' => [
                'reference' => $this->stringOf($this->propertyOf($event, 'reference')),
                'amount_cent' => $this->intOf($this->propertyOf($event, 'amountCent')),
                'reason' => $this->stringOf($this->propertyOf($event, 'reason')),
            ],
        ]);
    }
}
