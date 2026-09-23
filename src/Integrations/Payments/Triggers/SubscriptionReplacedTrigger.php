<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * A purchase ended another agreement of the same buyer, because the sold
 * product says it replaces it.
 *
 * `payments.subscription_cancelled` fires for the old agreement as well; this
 * one says why, so the "sorry to see you go" flow can stay away from somebody
 * who just bought the bigger plan. The product filters match what was bought;
 * `replaced_product` narrows to what it replaced.
 */
class SubscriptionReplacedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.subscription_replaced';
    }

    public static function label(): string
    {
        return 'Subscription Replaced';
    }

    public static function description(): ?string
    {
        return 'Triggered when a purchase ends an earlier subscription of the same customer, as the product is set up to do.';
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
                'handle' => 'replaced_product',
                'label' => 'Replaced product',
                'type' => 'select',
                'options_source' => 'payments.products',
                'required' => false,
                'help' => 'Only when this product was replaced. Leave empty for every product.',
            ],
        ]);
    }

    public static function outputSchema(): array
    {
        return [
            'replaced' => self::subscriptionOutputSchema(),
            'purchase' => self::paymentOutputSchema(),
            'replacement' => self::subscriptionOutputSchema(),
            'credit' => [
                'cent' => 'integer',
                'days' => 'integer',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        if (! $this->matchesProduct($event, $config)) {
            return false;
        }

        $replaced = $this->filterValue($config, 'replaced_product');

        return $replaced === null || ($this->subscriptionOf($event, 'replaced')['product'] ?? null) === $replaced;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'replaced' => $this->subscriptionOf($event, 'replaced'),
            'purchase' => $this->paymentOf($event, 'purchase'),
            'replacement' => $this->subscriptionOf($event, 'replacement'),
            'credit' => [
                'cent' => $this->intOf($this->propertyOf($event, 'creditCent')),
                'days' => $this->intOf($this->propertyOf($event, 'creditDays')),
            ],
        ]);
    }

    protected function productCandidates(object|array $event): array
    {
        return $this->handlesOf([
            $this->paymentOf($event, 'purchase')['product'] ?? null,
            $this->subscriptionOf($event, 'replacement')['product'] ?? null,
        ]);
    }
}
