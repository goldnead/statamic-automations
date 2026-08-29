<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\CalCom\Concerns\FlattensCalComBookings;

/**
 * Ein Termin wurde auf eine andere Zeit gelegt.
 *
 * Wichtig fuer jeden Ablauf, der an diesem Auslöser haengt: **cal.com verlegt
 * keine Buchung, es ersetzt sie.** Die alte Buchung wird abgesagt und eine neue
 * mit eigener `uid` angelegt.
 *
 * Was das fuer den Kontext heisst:
 *
 *   - `booking.uid`, `booking.starts_at`, `booking.ends_at` sind der **neue**
 *     Termin.
 *   - `booking.rescheduled_from_uid`, `booking.rescheduled_from_starts_at` und
 *     `booking.rescheduled_from_ends_at` sind die **alte** Buchung und ihr
 *     alter Termin.
 *   - Wer den alten Termin in einem eigenen System nachhaelt, sucht ihn ueber
 *     `rescheduled_from_uid` und nicht ueber `uid`.
 *
 * Der Grund, falls angegeben, steht in `booking.reschedule_reason`.
 */
class BookingRescheduledTrigger implements AutomationTrigger
{
    use FlattensCalComBookings;

    public static function handle(): string
    {
        return 'cal_com.booking_rescheduled';
    }

    public static function label(): string
    {
        return 'Booking Rescheduled (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a booking is moved to a different time.';
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
        return $this->isTriggerEvent($event, 'BOOKING_RESCHEDULED')
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
