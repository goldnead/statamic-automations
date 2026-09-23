<?php

namespace Goldnead\StatamicAutomations\Integrations\Affiliates\Triggers;

/**
 * A partner became active: approved in the Control Panel, or on sign-up with
 * automatic approval. `partner.code` is the referral code the welcome mail
 * hands over.
 */
class PartnerApprovedTrigger extends PartnerAppliedTrigger
{
    public static function handle(): string
    {
        return 'affiliates.partner_approved';
    }

    public static function label(): string
    {
        return 'Partner Approved';
    }

    public static function description(): ?string
    {
        return 'Triggered when a partner is approved and can start referring.';
    }
}
