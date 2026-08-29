<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;

/**
 * Eine Coaching-Session wurde angelegt.
 *
 * VocalFlow schickt das Ereignis, sobald der Datensatz entsteht — bei
 * **jedem** entstehenden Datensatz.
 *
 * Der Ablauf, an den man hier haengt, ist die Vorbereitung: Unterlagen
 * schicken, den Kalendereintrag bestaetigen, eine Aufgabe im CRM anlegen.
 *
 * ## Der Zustandsfilter ist hier keine Feinheit
 *
 * "Angelegt" heisst nicht "steht als Termin an". `session.status` ist beim
 * Anlegen nicht verlaesslich `scheduled`:
 *
 *   - Der Spalten-Default in VocalFlow ist `draft`. Ein angefangener und
 *     liegen gelassener Entwurf loest dieses Ereignis aus.
 *   - Der Import von Alt-Sitzungen legt sie direkt mit `completed` an. Eine
 *     einmalige Migration schickt dann ein `session.created` je importierter
 *     Stunde, alle mit einem Termin in der Vergangenheit.
 *
 * Ein Ablauf "Unterlagen zur Vorbereitung schicken" ohne den Filter `status`
 * auf `scheduled` mailt beim naechsten Import an jeden Studenten einmal pro
 * Altstunde. Wer diesen Auslöser benutzt, setzt den Filter.
 *
 * Nicht zu verwechseln mit {@see SessionPublishedTrigger}: angelegt ist eine
 * Session, die stattfinden wird, veroeffentlicht eine, deren Protokoll fertig
 * ist und die der Student ansehen kann. Dazwischen liegt die ganze Session.
 */
class SessionCreatedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return 'vocalflow.session_created';
    }

    public static function label(): string
    {
        return 'Session Created (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a coaching session is scheduled in VocalFlow.';
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
        return self::sessionOutputSchema();
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->isEvent($event, 'session.created')
            && $this->matchesSessionType($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'session' => $this->sessionOf($event),
            'student' => $this->studentOf($event, 'session'),
            'coach' => $this->coachOf($event, 'session'),
            'vocalflow' => $this->envelopeOf($event),
        ]);
    }
}
