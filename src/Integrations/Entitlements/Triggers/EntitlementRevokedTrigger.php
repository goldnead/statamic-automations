<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Concerns\FlattensEntitlements;

/**
 * Access was taken away deliberately.
 *
 * `reason` is always filled — the addon refuses a revocation without one — so
 * it is safe to put straight into a notification. `actor` is the point of the
 * whole trigger: a chargeback handled by a webhook and a refund granted by a
 * person produce the same row, and a flow that treats them alike will mail an
 * apology to somebody who charged back.
 *
 * Fires once per actual transition. Revoking an already revoked grant is a
 * silent no-op on the addon's side, so this cannot double-fire.
 */
class EntitlementRevokedTrigger implements AutomationTrigger
{
    use FlattensEntitlements;

    public static function handle(): string
    {
        return 'entitlements.revoked';
    }

    public static function label(): string
    {
        return 'Access Revoked';
    }

    public static function description(): ?string
    {
        return 'Triggered when access is deliberately withdrawn, carrying the reason and who did it.';
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
            'reason' => 'string',
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
            'reason' => $this->reasonOf($event),
            'previous_state' => $this->previousStateOf($event),
            'actor' => $this->actorOf($event),
        ]);
    }
}
