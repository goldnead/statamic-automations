<?php

namespace Goldnead\StatamicAutomations\Integrations\Affiliates\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Affiliates\Concerns\FlattensAffiliates;

/**
 * A sale earned a partner a commission, or a joint-venture share.
 *
 * Once per commission row: a payment booked twice starts nothing the second
 * time. The partner is read off the commission's relation, so a flow can write
 * "you earned 30 € on the choir course" without a lookup of its own.
 */
class CommissionEarnedTrigger implements AutomationTrigger
{
    use FlattensAffiliates;

    public static function handle(): string
    {
        return 'affiliates.commission_earned';
    }

    public static function label(): string
    {
        return 'Commission Earned';
    }

    public static function description(): ?string
    {
        return 'Triggered when a sale earns a partner a commission or a joint-venture share.';
    }

    public static function group(): string
    {
        return 'Affiliates';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'kind',
                'label' => 'Kind',
                'type' => 'select',
                'options' => [
                    ['value' => 'sale', 'label' => 'Sale'],
                    ['value' => 'recurring', 'label' => 'Recurring payment'],
                    ['value' => 'bump', 'label' => 'Order bump'],
                    ['value' => 'upsell', 'label' => 'Upsell'],
                    ['value' => 'jv', 'label' => 'Joint venture'],
                ],
                'required' => false,
                'help' => 'Leave empty for every kind.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'commission' => self::commissionOutputSchema(),
            'partner' => self::partnerOutputSchema(),
            'email' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $kind = is_string($config['kind'] ?? null) ? trim($config['kind']) : '';

        return $kind === '' || $this->read($this->read($event, 'commission'), 'kind') === $kind;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $commission = $this->read($event, 'commission');
        $partner = $this->partnerOf($this->read($commission, 'partner'));

        return AutomationContext::make([
            'commission' => $this->commissionOf($commission),
            'partner' => $partner,
            'email' => $partner['email'] ?? null,
        ]);
    }
}
