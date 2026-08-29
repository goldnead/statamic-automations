<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;

/**
 * An einer Aufgabe hat sich etwas geaendert.
 *
 * Der einzige Auslöser dieses Anschlusses, der mehrfach fuer denselben Vorgang
 * feuert. Das hat zwei Folgen, die man kennen muss:
 *
 * **Der Filter ist hier nicht Beiwerk, sondern das Werkzeug.** Ohne ihn laeuft
 * der Ablauf bei jeder Aenderung, auch wenn nur der Titel korrigiert wurde. Was
 * man fast immer meint, ist "die Aufgabe ist jetzt fertig", und dafuer gibt es
 * zwei Wege: den Filter `status` auf `completed`, oder eine Bedingung auf
 * `changed_to.status`. Der Unterschied zaehlt. Der Filter passt bei **jeder**
 * Aenderung an einer schon fertigen Aufgabe, die Bedingung nur bei der
 * Aenderung, die sie fertig gemacht hat. Fuer eine Gratulationsmail ist die
 * Bedingung die richtige.
 *
 * **Der zweite Weg muss von Hand getippt werden.** `changed_to` ist eine Karte
 * mit beliebigen Schluesseln — welche Felder darin stehen, weiss erst die
 * Nutzlast. Das Schema kann sie deshalb nur als `array` ankuendigen, und der
 * Datenpicker im Editor zeigt `changed_to` als **einen** Eintrag statt
 * `changed_to.status` einzeln anzubieten. Zur Laufzeit traegt der Pfad, er
 * steht nur nicht in der Liste. Die Alternative waere, drei feste Felder zu
 * erfinden und damit zu behaupten, es koennten sich nur diese drei aendern.
 *
 * **`changed_from` und `changed_to` sind nicht immer da.** VocalFlow legt
 * `data.changes` nur bei, wenn es den Zustand davor kennt. Fehlt es, sind beide
 * Karten leer und `fields` ist eine leere Liste. Eine Bedingung auf
 * `changed_to.status` laeuft dann ins Leere statt falsch zu treffen, was die
 * richtige Richtung ist.
 *
 * **Ein geleertes Feld steht als leere Zeichenkette da, nicht als Luecke.** Wer
 * eine Faelligkeit streicht, erzeugt `{"due_date": {"to": null}}`; in
 * `changed_to` steht dann `""` und in `fields` das Feld. Wuerde der Schluessel
 * fehlen, waere "gestrichen" von "gar nicht angefasst" nicht zu unterscheiden.
 *
 * **Beide Karten fuehren Text, auch fuer Zahlen.** Aus `15` wird `"15"`. Eine
 * Bedingung "groesser als" auf `changed_to.estimated_duration_minutes`
 * vergleicht deshalb Zeichenketten. Wer auf eine Zahl vergleichen will, nimmt
 * `task.estimated_duration_minutes` — dort steht sie als Zahl.
 *
 * **Hinweis zum Betrieb:** VocalFlow dispatcht dieses Ereignis heute nicht.
 * Dass hier nichts ankommt, ist dann eine Luecke auf der anderen Seite und kein
 * Fehler dieses Auslösers.
 */
class TaskUpdatedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return 'vocalflow.task_updated';
    }

    public static function label(): string
    {
        return 'Task Updated (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a practice task changes in VocalFlow. Exposes what changed under changed_from / changed_to.';
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
        return self::taskFilterSchema();
    }

    public static function outputSchema(): array
    {
        return self::taskOutputSchema(withChanges: true);
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->isEvent($event, 'task.updated')
            && $this->matchesTaskFilters($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'task' => $this->taskOf($event),
            'student' => $this->studentOf($event, 'task'),
            'coach' => $this->coachOf($event, 'task'),
            'session' => $this->taskSessionOf($event),
            'vocalflow' => $this->envelopeOf($event),
            ...$this->changesOf($event),
        ]);
    }
}
