<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * The invited person took their seat. Access follows from the offers addon;
 * the welcome mail belongs here.
 */
class SeatAcceptedTrigger extends SeatInvitedTrigger
{
    public static function handle(): string
    {
        return 'offers.seat_accepted';
    }

    public static function label(): string
    {
        return 'Seat Accepted';
    }

    public static function description(): ?string
    {
        return 'Triggered when an invited person takes their seat. The run is about that person.';
    }
}
