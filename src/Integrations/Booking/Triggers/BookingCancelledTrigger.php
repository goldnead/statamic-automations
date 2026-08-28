<?php

namespace Goldnead\StatamicAutomations\Integrations\Booking\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Booking\Concerns\FlattensBookings;

/**
 * A booking was called off, by whoever holds it or by the host.
 *
 * Covers a rejection as well as a cancellation; `booking.status` tells the two
 * apart (`cancelled` against `rejected`) for a flow that needs to word them
 * differently.
 *
 * Fires once. A booking that is already cancelled absorbs a second cancellation
 * silently on the addon's side, and a cancelled booking is never brought back
 * to life by a reschedule arriving afterwards.
 */
class BookingCancelledTrigger implements AutomationTrigger
{
    use FlattensBookings;

    public static function handle(): string
    {
        return 'booking.cancelled';
    }

    public static function label(): string
    {
        return 'Booking Cancelled';
    }

    public static function description(): ?string
    {
        return 'Triggered when a booking is cancelled or rejected.';
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
