<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * An offer reached its quantity limit. Once per offer.
 *
 * No person here: the run is for the team, a waitlist mail or switching a
 * page. Open checkouts of the last hour count as sold, as the limit does.
 */
class OfferSoldOutTrigger extends OfferTrigger
{
    public static function handle(): string
    {
        return 'offers.sold_out';
    }

    public static function label(): string
    {
        return 'Offer Sold Out';
    }

    public static function description(): ?string
    {
        return 'Triggered once when an offer reaches its quantity limit. For your team or a waiting list, not for a buyer.';
    }

    public static function outputSchema(): array
    {
        return [
            'offer' => self::offerOutputSchema(),
            'quantity_limit' => 'integer',
            'sold' => 'integer',
        ];
    }

    protected function context(object|array $event): array
    {
        $offer = $this->propertyOf($event, 'offer');

        return [
            'offer' => $this->offerOf($offer),
            'quantity_limit' => is_object($offer) ? $this->intOf($offer->quantity_limit ?? null) : null,
            'sold' => $this->intOf($this->propertyOf($event, 'sold')),
        ];
    }
}
