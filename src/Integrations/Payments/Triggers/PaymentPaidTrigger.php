<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Money arrived.
 *
 * Fired from the fulfilment path, which means exactly once per payment even
 * when the provider delivers its webhook three times. A listener here is a
 * listener on the same claim that grants entitlements.
 */
class PaymentPaidTrigger implements AutomationTrigger
{
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
