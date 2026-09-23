<?php

namespace Goldnead\StatamicAutomations\Integrations\Affiliates\Triggers;

/**
 * A refund, a chargeback or somebody in the Control Panel took back all or
 * part of a commission.
 *
 * For a commission already paid out, `commission` is the negative claw-back
 * row, and its `amount_cent` is below zero. `commission.reason` says why.
 */
class CommissionReversedTrigger extends CommissionEarnedTrigger
{
    public static function handle(): string
    {
        return 'affiliates.commission_reversed';
    }

    public static function label(): string
    {
        return 'Commission Reversed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a commission is taken back after a refund, a chargeback or by hand.';
    }
}
