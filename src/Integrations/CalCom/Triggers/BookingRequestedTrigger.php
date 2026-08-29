<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\CalCom\Concerns\FlattensCalComBookings;

/**
 * Jemand hat einen Termin angefragt, der noch bestaetigt werden muss.
 *
 * Nur bei Terminarten mit Bestaetigungspflicht (`requiresConfirmation`). Der
 * Termin steht noch nicht: `booking.status` ist `PENDING`, und danach folgt
 * entweder {@see BookingCreatedTrigger} oder {@see BookingRejectedTrigger}.
 *
 * Hier gehoert die Meldung an den Veranstalter hin, nicht die Zusage an den
 * Gast. Wer an diesen Auslöser eine Bestaetigungsmail haengt, sagt einen Termin
 * zu, ueber den noch niemand entschieden hat.
 */
class BookingRequestedTrigger implements AutomationTrigger
{
    use FlattensCalComBookings;

    public static function handle(): string
    {
        return 'cal_com.booking_requested';
    }

    public static function label(): string
    {
        return 'Booking Requested (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a booking is requested and still awaits confirmation.';
    }

    public static function group(): string
    {
        return 'cal.com';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::eventTypeFilterSchema();
    }

    public static function outputSchema(): array
    {
        return self::calComOutputSchema();
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->isTriggerEvent($event, 'BOOKING_REQUESTED')
            && $this->matchesEventType($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'booking' => $this->bookingOf($event),
            'cal_com' => $this->envelopeOf($event),
        ]);
    }
}
