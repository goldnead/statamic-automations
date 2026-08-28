<?php

namespace Goldnead\StatamicAutomations\Integrations\Booking\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Booking\Concerns\FlattensBookings;

/**
 * A booking moved to a different time.
 *
 * `booking.scheduled_at` is the new time. The old one is gone: the addon
 * overwrites the row rather than keeping a history, so a flow that needs to say
 * "moved from X to Y" has to have stored X itself on `booking.made`.
 *
 * The one trigger in this group that can repeat. The booking addon writes and
 * announces a reschedule without checking whether anything actually changed, so
 * a provider redelivering the same reschedule fires this twice. Anything
 * expensive behind it wants a deduplication step in front, keyed on
 * `booking.external_id` together with `booking.scheduled_at`.
 */
class BookingRescheduledTrigger implements AutomationTrigger
{
    use FlattensBookings;

    public static function handle(): string
    {
        return 'booking.rescheduled';
    }

    public static function label(): string
    {
        return 'Booking Rescheduled';
    }

    public static function description(): ?string
    {
        return 'Triggered when a booking is moved to a different time.';
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
