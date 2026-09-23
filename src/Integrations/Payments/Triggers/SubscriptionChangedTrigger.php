<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * An agreement moved to another product: an upgrade or a downgrade.
 *
 * `subscription` already carries the new product and amount; the old ones are
 * under `change`. The product filters match the new product. `direction` is
 * read off the amounts, not off `immediate`: an upgrade applies at once and a
 * downgrade at the next charge today, but "costs more" is the fact a mail is
 * about.
 */
class SubscriptionChangedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_changed';
    }

    public static function label(): string
    {
        return 'Subscription Changed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a subscription moves to another product, as an upgrade or a downgrade.';
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
                'handle' => 'direction',
                'label' => 'Direction',
                'type' => 'select',
                'options' => [
                    ['value' => 'upgrade', 'label' => 'Upgrade'],
                    ['value' => 'downgrade', 'label' => 'Downgrade'],
                ],
                'required' => false,
                'help' => 'Leave empty for both.',
            ],
        ]);
    }

    public static function outputSchema(): array
    {
        return [
            'subscription' => self::subscriptionOutputSchema(),
            'change' => [
                'from_product' => 'string',
                'to_product' => 'string',
                'from_amount_cent' => 'integer',
                'to_amount_cent' => 'integer',
                'proration_cent' => 'integer',
                'immediate' => 'boolean',
                'direction' => 'string',
                'by' => 'string',
            ],
            'proration_payment' => self::paymentOutputSchema(),
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        if (! $this->matchesProduct($event, $config)) {
            return false;
        }

        $direction = $this->filterValue($config, 'direction');

        return $direction === null || $this->changeOf($event)['direction'] === $direction;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'subscription' => $this->subscriptionOf($event),
            'change' => $this->changeOf($event),
            'proration_payment' => $this->paymentOf($event, 'prorationPayment'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function changeOf(object|array $event): array
    {
        $from = $this->intOf($this->propertyOf($event, 'fromAmountCent'));
        $to = $this->intOf($this->propertyOf($event, 'toAmountCent'));
        $immediate = $this->propertyOf($event, 'immediate');

        $direction = match (true) {
            $from !== null && $to !== null && $to > $from => 'upgrade',
            $from !== null && $to !== null && $to < $from => 'downgrade',
            // Same price, another product: the payments addon calls the
            // immediate path an upgrade, so this follows it.
            $immediate === true => 'upgrade',
            $immediate === false => 'downgrade',
            default => null,
        };

        return [
            'from_product' => $this->stringOf($this->propertyOf($event, 'fromProduct')),
            'to_product' => $this->stringOf($this->propertyOf($event, 'toProduct')),
            'from_amount_cent' => $from,
            'to_amount_cent' => $to,
            'proration_cent' => $this->intOf($this->propertyOf($event, 'prorationCent')),
            'immediate' => is_bool($immediate) ? $immediate : null,
            'direction' => $direction,
            'by' => $this->stringOf($this->propertyOf($event, 'by')),
        ];
    }
}
