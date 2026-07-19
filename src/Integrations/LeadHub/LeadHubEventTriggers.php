<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub;

use Goldnead\StatamicAutomations\Automations;

/**
 * Registers LeadHub domain events as automation *event triggers* through the
 * public {@see Automations::registerEventTrigger()} API.
 *
 * Unlike the class-based LeadHub triggers (which are wired via
 * HandleLeadHubEvent), the score-changed trigger has no bespoke trigger class:
 * it maps the LeadHubContactScoreChanged event straight into the generic
 * EventTrigger. Registration is guarded on the event class existing so the
 * automations addon boots cleanly when LeadHub is absent (or older).
 */
class LeadHubEventTriggers
{
    /** Default FQCN of the LeadHub score-changed event (overridable via config). */
    public const SCORE_CHANGED_EVENT = 'Goldnead\\Leadhub\\Events\\LeadHubContactScoreChanged';

    /**
     * Resolve the event class-string, allowing a config override so a future
     * rename (or a test double) can plug in without touching code.
     */
    public static function scoreChangedEventClass(): string
    {
        return (string) config(
            'automations.integrations.leadhub.score_changed_event',
            self::SCORE_CHANGED_EVENT,
        );
    }

    /**
     * The `contact_score_changed` event-trigger definition. The event exposes
     * a `toArray()` (contact_id, email, old_score, new_score, delta, reason),
     * so `payload: '*'` maps those keys straight onto the automation context.
     *
     * @return array<string, mixed>
     */
    public static function scoreChangedDefinition(): array
    {
        return [
            'handle' => 'contact_score_changed',
            'label' => 'Contact score changed',
            'group' => 'LeadHub',
            'description' => "Fires when a LeadHub contact's lead score changes.",
            'payload' => '*',
            'output_schema' => [
                'contact_id' => 'string',
                'email' => 'string',
                'old_score' => 'integer',
                'new_score' => 'integer',
                'delta' => 'integer',
                'reason' => 'string',
            ],
        ];
    }

    /**
     * Register the score-changed event trigger. No-op when the LeadHub event
     * class is not present, so this is safe to call unconditionally from boot.
     */
    public static function register(Automations $automations): void
    {
        $eventClass = self::scoreChangedEventClass();

        if ($eventClass === '' || ! class_exists($eventClass)) {
            return;
        }

        $automations
            ->registerBuiltIn('contact_score_changed')
            ->registerEventTrigger($eventClass, self::scoreChangedDefinition());
    }
}
