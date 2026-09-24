<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * A purchase of several seats opened a pool the buyer now hands out.
 *
 * The run is about the buyer who owns the pool: the mail that belongs here
 * explains how to invite the others.
 */
class SeatPoolOpenedTrigger extends OfferTrigger
{
    public static function handle(): string
    {
        return 'offers.seat_pool_opened';
    }

    public static function label(): string
    {
        return 'Seat Pool Opened';
    }

    public static function description(): ?string
    {
        return 'Triggered when a purchase of several seats opens a pool for the buyer to hand out.';
    }

    public static function outputSchema(): array
    {
        return [
            'offer' => self::offerOutputSchema(),
            'pool' => self::poolOutputSchema(),
            'email' => 'string',
        ];
    }

    protected function context(object|array $event): array
    {
        $pool = $this->propertyOf($event, 'pool');
        $flat = $this->poolOf($pool);

        return [
            'offer' => $this->offerOfPool($pool),
            'pool' => $flat,
            'email' => $flat['owner']['email'] ?? null,
        ];
    }
}
