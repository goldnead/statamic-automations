<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

/**
 * A seat was taken back. `previous_status` says whether somebody had already
 * taken it (`claimed`) or only been invited; `reason` why, where it is known.
 */
class SeatRevokedTrigger extends SeatInvitedTrigger
{
    public static function handle(): string
    {
        return 'offers.seat_revoked';
    }

    public static function label(): string
    {
        return 'Seat Revoked';
    }

    public static function description(): ?string
    {
        return 'Triggered when a seat is taken back, whether it was already taken or only invited.';
    }

    public static function outputSchema(): array
    {
        return parent::outputSchema() + [
            'previous_status' => 'string',
            'reason' => 'string',
        ];
    }

    protected function context(object|array $event): array
    {
        return parent::context($event) + [
            'previous_status' => $this->stringOf($this->propertyOf($event, 'previousStatus')),
            'reason' => $this->stringOf($this->propertyOf($event, 'reason')),
        ];
    }
}
