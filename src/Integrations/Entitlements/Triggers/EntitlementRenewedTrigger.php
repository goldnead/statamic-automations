<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Concerns\FlattensEntitlements;

/**
 * An existing grant now runs longer.
 *
 * Deliberately not a second `entitlements.granted`, and the difference matters
 * to whoever is listening: the subject had access before and has it after, so a
 * welcome flow attached here would greet the same person every month.
 *
 * `previous_expires_at` is the window this renewal replaced. The row no longer
 * answers "until when did they have it before", which is the question an audit
 * asks, so the event carries it.
 */
class EntitlementRenewedTrigger implements AutomationTrigger
{
    use FlattensEntitlements;

    public static function handle(): string
    {
        return 'entitlements.renewed';
    }

    public static function label(): string
    {
        return 'Access Renewed';
    }

    public static function description(): ?string
    {
        return 'Triggered when an existing grant is extended, carrying the window it replaced.';
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
            'previous_expires_at' => 'string',
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
            'previous_expires_at' => $this->dateOf(
                $this->propertyOf($event, 'previousExpiresAt')
                    ?? $this->propertyOf($event, 'previous_expires_at')
            ),
            'actor' => $this->actorOf($event),
        ]);
    }
}
