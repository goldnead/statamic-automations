<?php

namespace Goldnead\StatamicAutomations\Integrations\Booking\Concerns;

/**
 * Turns the booking a booking event carries into a plain array.
 *
 * `endpoint` is the field to notice. A site runs several booking endpoints at
 * once — a free consultation and a paid lesson are different funnels with
 * different secrets — and every one of them fires the same three events. A flow
 * that does not filter on it will send the paid-lesson mail to somebody who
 * booked a free call, which is why the filter is on all three triggers and the
 * field is in every context.
 */
trait FlattensBookings
{
    /**
     * The booking, flattened.
     *
     * `scheduled_at` and `timezone` are separate on purpose, as they are in the
     * addon: the instant is UTC and the timezone is the one the person booked
     * in. A mail that prints the instant without the timezone tells somebody in
     * Vancouver the wrong hour.
     *
     * @return array<string, mixed>
     */
    protected function bookingOf(object|array $event, string $key = 'booking'): array
    {
        $booking = $this->propertyOf($event, $key);

        if (is_array($booking)) {
            return $booking;
        }

        if (! is_object($booking)) {
            return [];
        }

        return [
            'id' => $booking->id ?? null,
            'endpoint' => $booking->endpoint ?? null,
            'external_id' => $booking->external_id ?? null,
            'status' => $booking->status ?? null,
            'scheduled_at' => $this->dateOf($booking->scheduled_at ?? null),
            'timezone' => $booking->timezone ?? null,
            'duration_minutes' => $booking->duration_minutes ?? null,
            'name' => $booking->name ?? null,
            'email' => $booking->email ?? null,
            'meeting_url' => $booking->meeting_url ?? null,
            'cancelled_at' => $this->dateOf($booking->cancelled_at ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function bookingOutputSchema(): array
    {
        return [
            'id' => 'string',
            'endpoint' => 'string',
            'external_id' => 'string',
            'status' => 'string',
            'scheduled_at' => 'string',
            'timezone' => 'string',
            'duration_minutes' => 'integer',
            'name' => 'string',
            'email' => 'string',
            'meeting_url' => 'string',
            'cancelled_at' => 'string',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function endpointFilterSchema(): array
    {
        return [
            [
                'handle' => 'endpoint',
                'label' => 'Endpoint',
                'type' => 'text',
                'required' => false,
                'help' => 'The booking endpoint, as configured in the booking addon. Leave empty for every endpoint.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function matchesEndpoint(object|array $event, array $config): bool
    {
        $endpoint = $config['endpoint'] ?? null;

        if (! $endpoint) {
            return true;
        }

        return ($this->bookingOf($event)['endpoint'] ?? null) === $endpoint;
    }

    protected function propertyOf(object|array $event, string $key): mixed
    {
        return is_array($event) ? ($event[$key] ?? null) : ($event->{$key} ?? null);
    }

    protected function dateOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
