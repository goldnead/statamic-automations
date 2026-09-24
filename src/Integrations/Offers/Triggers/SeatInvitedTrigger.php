<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * The buyer invited somebody to one of their seats.
 *
 * The run is about the invited person (`seat.email`). The invitation link
 * itself is not in the context: it carries the seat's claim token.
 */
class SeatInvitedTrigger extends OfferTrigger
{
    public static function handle(): string
    {
        return 'offers.seat_invited';
    }

    public static function label(): string
    {
        return 'Seat Invited';
    }

    public static function description(): ?string
    {
        return 'Triggered when a buyer invites somebody to one of their seats. The run is about the invited person.';
    }

    public static function outputSchema(): array
    {
        return [
            'offer' => self::offerOutputSchema(),
            'pool' => self::poolOutputSchema(),
            'seat' => self::seatOutputSchema(),
            'email' => 'string',
        ];
    }

    protected function context(object|array $event): array
    {
        $pool = $this->propertyOf($event, 'pool');
        $seat = $this->seatOf($this->propertyOf($event, 'seat'));

        return [
            'offer' => $this->offerOfPool($pool),
            'pool' => $this->poolOf($pool),
            'seat' => $seat,
            'email' => $seat['email'] ?? null,
        ];
    }
}
