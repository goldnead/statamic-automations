<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Concerns\FlattensEntitlements;

/**
 * A grant's window has closed on its own.
 *
 * The odd one of the five: nothing wrote anything. The grant expired because
 * the clock moved past its end, so the event comes from a scheduled pass rather
 * than from a request, and it can arrive hours after the moment it describes. A
 * flow here should say "your access has ended", not "your access ends now".
 *
 * `granted_access_until` is the instant access actually stopped — the grace
 * date when a grace period was in play, the expiry date otherwise — so a flow
 * does not have to work out which of the two applied.
 */
class EntitlementExpiredTrigger implements AutomationTrigger
{
    use FlattensEntitlements;

    public static function handle(): string
    {
        return 'entitlements.expired';
    }

    public static function label(): string
    {
        return 'Access Expired';
    }

    public static function description(): ?string
    {
        return 'Triggered by the scheduled pass when a grant runs past its end date.';
    }

    public static function group(): string
    {
        return 'Entitlements';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::entitlementFilterSchema();
    }

    public static function outputSchema(): array
    {
        return [
            'entitlement' => self::entitlementOutputSchema(),
            'granted_access_until' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->matchesEntitlement($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'entitlement' => $this->entitlementOf($event),
            'granted_access_until' => $this->dateOf(
                $this->propertyOf($event, 'grantedAccessUntil')
                    ?? $this->propertyOf($event, 'granted_access_until')
            ),
        ]);
    }
}
