<?php

namespace Goldnead\StatamicAutomations\Integrations\Affiliates\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Affiliates\Concerns\FlattensAffiliates;

/**
 * Somebody signed up as a partner through the application form.
 *
 * `partner.status` is `pending` unless the site approves automatically, in
 * which case `affiliates.partner_approved` follows at once.
 */
class PartnerAppliedTrigger implements AutomationTrigger
{
    use FlattensAffiliates;

    public static function handle(): string
    {
        return 'affiliates.partner_applied';
    }

    public static function label(): string
    {
        return 'Partner Applied';
    }

    public static function description(): ?string
    {
        return 'Triggered when somebody applies to the partner programme.';
    }

    public static function group(): string
    {
        return 'Affiliates';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'partner' => self::partnerOutputSchema(),
            'email' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $partner = $this->partnerOf($this->read($event, 'partner'));

        return AutomationContext::make([
            'partner' => $partner,
            'email' => $partner['email'] ?? null,
        ]);
    }
}
