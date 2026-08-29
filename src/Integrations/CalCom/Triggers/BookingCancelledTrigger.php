<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\CalCom\Concerns\FlattensCalComBookings;

/**
 * Ein bestehender Termin wurde abgesagt.
 *
 * Der Grund steht in `booking.cancellation_reason`, sofern einer angegeben
 * wurde; das Feld bleibt leer, wenn nicht.
 *
 * Nicht zu verwechseln mit {@see BookingRejectedTrigger}: abgesagt wird ein
 * Termin, der stand, abgelehnt eine Anfrage, die nie stand. Eine Mail, die
 * beides gleich behandelt, schreibt jemandem "schade, dass es nicht klappt" zu
 * einem Termin, den er nie hatte.
 *
 * Eine Verlegung sagt bei cal.com die alte Buchung ebenfalls ab. Dieser
 * Auslöser feuert dabei nicht — cal.com schickt dafuer BOOKING_RESCHEDULED,
 * siehe {@see BookingRescheduledTrigger}.
 */
class BookingCancelledTrigger implements AutomationTrigger
{
    use FlattensCalComBookings;

    public static function handle(): string
    {
        return 'cal_com.booking_cancelled';
    }

    public static function label(): string
    {
        return 'Booking Cancelled (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Triggered when an existing booking is cancelled.';
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
        return $this->isTriggerEvent($event, 'BOOKING_CANCELLED')
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
