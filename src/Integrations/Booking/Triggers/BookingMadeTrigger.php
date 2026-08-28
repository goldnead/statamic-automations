<?php

namespace Goldnead\StatamicAutomations\Integrations\Booking\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Booking\Concerns\FlattensBookings;

/**
 * Somebody booked a slot.
 *
 * Fires once per booking, held by a unique key on (endpoint, external id) in
 * the booking addon, so a redelivered webhook does not send a second
 * confirmation.
 *
 * It does not fire for a booking that still needs approval — that one is
 * `requested` on the addon's side and announces nothing. What reaches here is a
 * slot that is actually taken.
 */
class BookingMadeTrigger implements AutomationTrigger
{
    use FlattensBookings;

    public static function handle(): string
    {
        return 'booking.made';
    }

    public static function label(): string
    {
        return 'Booking Made';
    }

    public static function description(): ?string
    {
        return 'Triggered when a booking is confirmed for one of the configured endpoints.';
    }

    public static function group(): string
    {
        return 'Booking';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::endpointFilterSchema();
    }

    public static function outputSchema(): array
    {
        return [
            'booking' => self::bookingOutputSchema(),
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->matchesEndpoint($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'booking' => $this->bookingOf($event),
        ]);
    }
}
