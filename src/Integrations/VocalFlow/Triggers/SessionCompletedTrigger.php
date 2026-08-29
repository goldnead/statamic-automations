<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;

/**
 * Eine Coaching-Session ist gehalten und abgeschlossen.
 *
 * `session.status` ist `completed`, `session.completed_at` traegt den Zeitpunkt
 * und `session.rating` die Bewertung, sofern eine vergeben wurde.
 *
 * Dieser Auslöser bringt als einziger den Zweig `completion` mit: VocalFlows
 * eigene Einschaetzung des Abschlusses. `completion.follow_up_required` und
 * `completion.referral_eligible` sind die beiden, an denen ein Ablauf
 * tatsaechlich haengt — die Nachfassmail und die Empfehlungsfrage sind
 * verschiedene Nachrichten und sollen nicht beide an alle gehen.
 *
 * Die drei Wahrheitswerte in `completion` koennen `null` sein, wenn VocalFlow
 * sie nicht mitgeschickt hat. Das ist ausdruecklich nicht dasselbe wie `false`,
 * und eine Bedingung, die beides gleich behandelt, verschickt die Nachfassmail
 * an alle oder an niemanden.
 *
 * **Hinweis zum Betrieb:** VocalFlow legt dieses Ereignis heute zwar als Abo an,
 * haengt den versendenden Zuhoerer aber nicht daran. Dass hier nichts ankommt,
 * ist dann eine Luecke auf der anderen Seite und kein Fehler dieses Auslösers.
 */
class SessionCompletedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return 'vocalflow.session_completed';
    }

    public static function label(): string
    {
        return 'Session Completed (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a coaching session has been held and marked completed.';
    }

    public static function group(): string
    {
        return 'VocalFlow';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::sessionTypeFilterSchema();
    }

    public static function outputSchema(): array
    {
        return self::sessionOutputSchema(withCompletion: true);
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->isEvent($event, 'session.completed')
            && $this->matchesSessionType($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'session' => $this->sessionOf($event),
            'completion' => $this->completionOf($event),
            'student' => $this->studentOf($event, 'session'),
            'coach' => $this->coachOf($event, 'session'),
            'vocalflow' => $this->envelopeOf($event),
        ]);
    }
}
