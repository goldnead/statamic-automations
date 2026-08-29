<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Http\Controllers\Web\VocalFlowSessionPublishedController;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowEvents;

/**
 * Das Protokoll einer Session ist veroeffentlicht und fuer den Studenten
 * sichtbar.
 *
 * Das ist der Moment, in dem sich der Ablauf "deine Aufzeichnung liegt bereit"
 * lohnt: vorher gibt es nichts anzusehen, und {@see SessionCompletedTrigger}
 * feuert schon, wenn die Session gehalten wurde — zwischen beidem liegt die
 * Nachbereitung, die Tage dauern kann.
 *
 * ## Warum dieser Auslöser so wenig traegt
 *
 * Er kommt ueber einen eigenen Endpunkt, und VocalFlow schickt dort genau zwei
 * Felder: `session_id` und `student_email`. Kein Umschlag, kein Zeitstempel,
 * keine Session-Daten, keine Person ausser der Adresse. Der Auslöser bildet
 * das ab und tut nicht so, als wuesste er mehr. Ein Schema, das Felder anbietet,
 * die dann leer ankommen, ist schlechter als ein kurzes: es kostet jeden, der
 * darauf baut, einen Testlauf, um festzustellen, dass nichts drin steht.
 *
 * Was ein Ablauf ausser der Adresse braucht, holt er sich selbst — die
 * Session-Kennung ist da, und die Partner-API von VocalFlow kennt sie.
 *
 * Der Umschlag unter `vocalflow` ist hier nicht empfangen, sondern gebaut
 * ({@see VocalFlowSessionPublishedController}). Er traegt `event` und
 * `received_at`, damit ein Verzweigungsknoten ueber alle sieben Auslöser dieses
 * Anschlusses gleich lesen kann und nicht fuer diesen einen eine Ausnahme
 * braucht.
 *
 * Kein Filter: es gibt nichts, wonach sich filtern liesse. Ein Feld anzubieten,
 * das auf jeder Nutzlast leer ist, waere ein Filter, der still nie passt.
 */
class SessionPublishedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return VocalFlowEvents::SESSION_PUBLISHED_HANDLE;
    }

    public static function label(): string
    {
        return 'Session Published (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a session recap is published in VocalFlow and becomes visible to the student.';
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
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'session' => [
                'id' => 'string',
            ],
            'student' => [
                'email' => 'string',
            ],
            'vocalflow' => [
                'event' => 'string',
                'received_at' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->isEvent($event, VocalFlowEvents::SESSION_PUBLISHED_EVENT);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'session' => [
                'id' => $this->str($this->branch($event, 'session')['id'] ?? null),
            ],
            'student' => [
                'email' => $this->str($this->branch($event, 'student')['email'] ?? null),
            ],
            'vocalflow' => [
                'event' => VocalFlowEvents::SESSION_PUBLISHED_EVENT,

                // Der Zeitpunkt des Empfangs, nicht der des Vorgangs. VocalFlow
                // schickt hier keinen mit, und einen zu erfinden waere schlimmer
                // als das Feld leer zu lassen: eine Bedingung darauf wuerde
                // rechnen, als waere es der Veroeffentlichungszeitpunkt.
                'received_at' => now()->format(\DATE_ATOM),
            ],
        ]);
    }
}
