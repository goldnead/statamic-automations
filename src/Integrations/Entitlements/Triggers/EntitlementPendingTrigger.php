<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Concerns\FlattensEntitlements;

/**
 * A grant is parked without access, waiting for a confirmation.
 *
 * The confirm-first pattern: a lead magnet is claimed, the grant is written,
 * and nothing is handed over until the double opt-in comes back. This trigger
 * is where the confirmation request is sent — the entitlements addon sends
 * nothing on its own.
 *
 * Nobody has access at this point. A flow that delivers the file here defeats
 * the confirmation it is supposed to be waiting for; the delivery belongs on
 * `entitlements.granted`, which fires when the claim comes back.
 *
 * Fires only on the transition into Pending, so somebody re-submitting the same
 * form while a confirmation is already open does not get a second request.
 */
class EntitlementPendingTrigger implements AutomationTrigger
{
    use FlattensEntitlements;

    public static function handle(): string
    {
        return 'entitlements.pending';
    }

    public static function label(): string
    {
        return 'Access Pending Confirmation';
    }

    public static function description(): ?string
    {
        return 'Triggered when a grant is parked awaiting a confirmation, for example a double opt-in.';
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
