<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

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
        return [
            [
                'handle' => 'product',
                'label' => 'Product',
                'type' => 'text',
                'required' => false,
                'help' => 'The product handle. Leave empty for every product.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'payment' => [
                'id' => 'string',
                'product' => 'string',
                'amount_cent' => 'integer',
                'currency' => 'string',
                'discount_code' => 'string',
                'status' => 'string',
                'email' => 'string',
                'name' => 'string',
                'provider' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $product = $config['product'] ?? null;

        if (! $product) {
            return true;
        }

        return ($this->paymentOf($event)['product'] ?? null) === $product;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'payment' => $this->paymentOf($event),
        ]);
    }

    /**
     * The payment, flattened.
     *
     * @return array<string, mixed>
     */
    protected function paymentOf(object|array $event): array
    {
        $payment = is_array($event) ? ($event['payment'] ?? null) : ($event->payment ?? null);

        if (is_array($payment)) {
            return $payment;
        }

        if (! is_object($payment)) {
            return [];
        }

        return [
            'id' => $payment->id ?? null,
            'product' => $payment->product ?? null,
            'amount_cent' => $payment->amount_cent ?? null,
            'currency' => $payment->currency ?? null,
            'discount_code' => $payment->discount_code ?? null,
            'status' => $payment->status ?? null,
            'email' => $payment->email ?? null,
            'name' => $payment->name ?? null,
            'provider' => $payment->provider ?? null,
        ];
    }
}
