<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * A seat pool closed, for example after a refund. Every seat in it lost its
 * access with it. The run is about the buyer who owned the pool.
 */
class SeatPoolClosedTrigger extends SeatPoolOpenedTrigger
{
    public static function handle(): string
    {
        return 'offers.seat_pool_closed';
    }

    public static function label(): string
    {
        return 'Seat Pool Closed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a seat pool closes, for example after a refund. Its seats lose access with it.';
    }

    public static function outputSchema(): array
    {
        $schema = parent::outputSchema();
        $email = $schema['email'];
        unset($schema['email']);

        return $schema + ['reason' => 'string', 'email' => $email];
    }

    protected function context(object|array $event): array
    {
        $context = parent::context($event);
        $email = $context['email'];
        unset($context['email']);

        return $context + [
            'reason' => $this->stringOf($this->propertyOf($event, 'reason')),
            'email' => $email,
        ];
    }
}
