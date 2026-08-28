<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Concerns\FlattensEntitlements;

/**
 * Somebody now has access.
 *
 * Fires on the transition into Active, once, whether the grant was written
 * straight to Active or won the claim out of Pending. It does not fire for a
 * grant with a future `starts_at`: that one is Scheduled, and a promise is not
 * an event. It gets its turn here when the clock reaches it.
 *
 * This is where the welcome mail belongs. The entitlements addon deliberately
 * sends nothing itself, and that hole is the reason this trigger exists: in the
 * system it was extracted from, the mail lived inside the provisioning call, so
 * a failing mail server could roll back a paid-for grant.
 *
 * `previous_state` tells the two paths apart. It is `pending` when a double
 * opt-in was just confirmed, and empty when the grant was created active.
 */
class EntitlementGrantedTrigger implements AutomationTrigger
{
    use FlattensEntitlements;

    public static function handle(): string
    {
        return 'entitlements.granted';
    }

    public static function label(): string
    {
        return 'Access Granted';
    }

    public static function description(): ?string
    {
        return 'Triggered when a grant becomes active, either on creation or when a pending confirmation is claimed.';
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
            'previous_state' => 'string',
            'actor' => self::actorOutputSchema(),
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
            'previous_state' => $this->previousStateOf($event),
            'actor' => $this->actorOf($event),
        ]);
    }
}
