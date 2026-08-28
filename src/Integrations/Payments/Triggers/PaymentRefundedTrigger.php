<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * Money went back to the buyer.
 *
 * Carries the movement and the verdict separately, because they answer
 * different questions. `refund.amount_cent` is what went back this time, which
 * is what an accounting step needs. `refund.is_full` is whether everything has
 * now been repaid, which is what a step that withdraws access needs — and only
 * that one is safe to hang a revocation on. A partial refund is still a paid
 * order: the thing was delivered and part of the money came back.
 *
 * The `only_full` filter exists so that distinction can be made in the editor
 * rather than in a condition node afterwards. It is off by default, so an
 * unconfigured trigger sees every refund.
 */
class PaymentRefundedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.refunded';
    }

    public static function label(): string
    {
        return 'Payment Refunded';
    }

    public static function description(): ?string
    {
        return 'Triggered when a refund is recorded against a payment, in full or in part.';
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
                'handle' => 'only_full',
                'label' => 'Only full refunds',
                'type' => 'toggle',
                'required' => false,
                'default' => false,
                'help' => 'Ignore partial refunds. Turn this on for anything that withdraws access.',
            ],
        ]);
    }

    public static function outputSchema(): array
    {
        return [
            'payment' => self::paymentOutputSchema(),
            'refund' => [
                'amount_cent' => 'integer',
                'is_full' => 'boolean',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        if (! $this->matchesProduct($event, $config)) {
            return false;
        }

        if (! ($config['only_full'] ?? false)) {
            return true;
        }

        return $this->refundOf($event)['is_full'] === true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'payment' => $this->paymentOf($event),
            'refund' => $this->refundOf($event),
        ]);
    }

    /**
     * The refund itself: what moved, and whether that was the last of it.
     *
     * The event names these `amountCent` and `isFull`. They are renamed here
     * because every other value in the run context is snake_case, and a data
     * picker that shows one camelCase token among forty is a picker people
     * mistype.
     *
     * @return array{amount_cent: int|null, is_full: bool}
     */
    protected function refundOf(object|array $event): array
    {
        $amount = $this->propertyOf($event, 'amountCent') ?? $this->propertyOf($event, 'amount_cent');
        $isFull = $this->propertyOf($event, 'isFull') ?? $this->propertyOf($event, 'is_full');

        return [
            'amount_cent' => is_numeric($amount) ? (int) $amount : null,
            'is_full' => $isFull === true,
        ];
    }
}
