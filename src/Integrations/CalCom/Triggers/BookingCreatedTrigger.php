<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\CalCom\Concerns\FlattensCalComBookings;

/**
 * Jemand hat einen Termin gebucht, und er steht.
 *
 * Der Normalfall bei einer Terminart ohne Bestaetigungspflicht. Braucht die
 * Terminart eine Bestaetigung, kommt zuerst {@see BookingRequestedTrigger} und
 * dieser Auslöser erst, wenn der Termin angenommen wurde.
 *
 * `booking.status` ist hier `ACCEPTED`.
 */
class BookingCreatedTrigger implements AutomationTrigger
{
    use FlattensCalComBookings;

    public static function handle(): string
    {
        return 'cal_com.booking_created';
    }

    public static function label(): string
    {
        return 'Booking Created (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a booking is made on cal.com and stands.';
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
        return $this->isTriggerEvent($event, 'BOOKING_CREATED')
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
