<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * An offer's short link now sends visitors to its fallback: the deadline
 * passed (`date`) or it sold out (`sold_out`).
 *
 * After a deadline the offers addon notices it on the first visit after it,
 * not at the second itself, so this can arrive later than the date.
 */
class ShortLinkSwitchedTrigger extends OfferTrigger
{
    public static function handle(): string
    {
        return 'offers.link_switched';
    }

    public static function label(): string
    {
        return 'Offer Link Switched';
    }

    public static function description(): ?string
    {
        return 'Triggered when an offer link starts sending visitors to its fallback, after its deadline or because it sold out. After a deadline it fires on the first visit after it.';
    }

    public static function schema(): array
    {
        return [
            self::offerFilterField(),
            [
                'handle' => 'reason',
                'label' => 'Reason',
                'type' => 'select',
                'options' => [
                    ['value' => 'date', 'label' => 'Deadline passed'],
                    ['value' => 'sold_out', 'label' => 'Sold out'],
                ],
                'required' => false,
                'help' => 'Leave empty for both.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'offer' => self::offerOutputSchema(),
            'reason' => 'string',
            'link' => [
                'slug' => 'string',
                'target' => 'string',
                'fallback' => 'string',
                'switch_at' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        if (! parent::matches($event, $config)) {
            return false;
        }

        $reason = $this->filterValue($config, 'reason');

        return $reason === null || $this->stringOf($this->propertyOf($event, 'reason')) === $reason;
    }

    protected function context(object|array $event): array
    {
        $offer = $this->propertyOf($event, 'offer');
        $has = is_object($offer);

        return [
            'offer' => $this->offerOf($offer),
            'reason' => $this->stringOf($this->propertyOf($event, 'reason')),
            'link' => [
                'slug' => $has ? $this->stringOf($offer->link_slug ?? null) : null,
                'target' => $has ? $this->stringOf($offer->link_target ?? null) : null,
                'fallback' => $has ? $this->stringOf($offer->link_fallback ?? null) : null,
                'switch_at' => $has ? $this->dateOf($offer->link_switch_at ?? null) : null,
            ],
        ];
    }
}
