<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComClient;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Holt die freien Zeiten einer Terminart aus cal.com.
 *
 * Der Knoten, der einen Ablauf handlungsfaehig macht, statt ihn nur berichten
 * zu lassen. Der Fall, fuer den er gebaut ist: jemand hat abgesagt, und statt
 * einer Mail "schade" geht eine Mail mit drei Terminvorschlaegen hinaus, die
 * wirklich frei sind. Die Vorschlaege stehen danach als `{{ node.slots }}` im
 * Kontext.
 *
 * ## Er liest nur
 *
 * Deshalb der einzige der drei cal.com-Knoten ohne Testmodus-Sperre: ein
 * Testlauf fragt wirklich bei cal.com nach und zeigt die echten freien Zeiten.
 * Das ist der Sinn eines Testlaufs an dieser Stelle. Eine Vorschau aus
 * erfundenen Zeiten waere nichts wert, und lesen aendert drueben nichts.
 *
 * ## Der doppelte Lauf, der hier anfaengt
 *
 * Bei cal.com richtet ein zweiter Lauf dieses Knotens nichts an. Beim
 * **naechsten** Knoten schon, und das ist die einzige Stelle in diesem
 * Anschluss, an der ein doppelter Lauf wirklich einen zweiten Termin erzeugt.
 *
 * Die Kette `Freie Zeiten holen` nach `Termin anlegen` sieht harmlos aus:
 * `{{ node.first }}` in das Startfeld, fertig. Sie ist es nicht. Beim zweiten
 * Lauf ist der Zeitpunkt aus dem ersten belegt (vom ersten Lauf), dieser Knoten
 * fragt neu und gibt deshalb den **naechsten** heraus. Der 409, mit dem cal.com
 * sonst den zweiten Lauf abfaengt, greift dann nie, und derselbe Mensch hat
 * zwei Termine und zwei Bestaetigungen.
 *
 * `first` ist also ausdruecklich **kein** Wert, der in einen Anlage-Knoten
 * gehoert. Dorthin gehoert, was der Kunde gewaehlt hat, aus dem Kontext des
 * Laufs. Wofuer `first` da ist: der fruehste Vorschlag in einer Mail, eine
 * Verzweigung darauf, ob ueberhaupt bald etwas frei ist.
 *
 * ## Leer ist hier nicht gleich leer
 *
 * Der Grund, warum diese Aktion mehr tut als eine Anfrage zu stellen. cal.com
 * antwortet auf `/v2/slots` mit einer nach Datum geschluesselten Liste, und
 * eine **unbekannte Terminart** ergibt dabei keinen Fehler, sondern `{}` mit
 * Status 200 — dieselbe Antwort wie ein ausgebuchter Kalender (geprueft am
 * 29.08.2026).
 *
 * Ein Ablauf mit einer vertippten oder inzwischen geloeschten Kennung wuerde
 * also nicht rot, sondern still nichts vorschlagen. Er verschickt dann Mails
 * ohne Termine, oder er verzweigt monatelang in den "keine Zeit frei"-Zweig,
 * und niemand sucht danach, weil nichts kaputt aussieht.
 *
 * Deshalb sieht diese Aktion bei einer leeren Antwort nach, ob es die
 * Terminart ueberhaupt gibt. Gibt es sie nicht, geht der Knoten rot und sagt
 * warum. Gibt es sie, ist `{{ node.count }}` gleich 0 und das ist eine echte
 * Auskunft: der Kalender ist in diesem Zeitraum voll.
 *
 * Die Gegenprobe laeuft nur auf dem leeren Pfad. Im Normalfall kostet sie
 * nichts.
 *
 * ## Der Zeitraum ist Pflicht
 *
 * cal.com verlangt `start` und `end` und lehnt ohne sie mit 400 ab. Beide sind
 * hier tokenfaehig, weil der uebliche Zeitraum relativ ist: von heute bis in
 * zwei Wochen. Ein Datum ohne Uhrzeit (`2026-09-01`) nimmt cal.com an.
 */
class GetSlotsAction implements AutomationAction
{
    /**
     * Wie viele Vorschlaege hoechstens im Kontext landen, wenn nichts anderes
     * eingestellt ist.
     *
     * Eine Grenze und keine Zierde: zwei Wochen einer 20-Minuten-Terminart
     * sind schnell mehrere hundert Zeitpunkte. Die stehen sonst alle im
     * Ablaufprotokoll, das damit unlesbar wird, und wer sie in eine Mail
     * einsetzt, verschickt eine Tapete.
     */
    protected const DEFAULT_LIMIT = 10;

    public function __construct(protected CalComClient $client) {}

    public static function handle(): string
    {
        return 'cal_com.get_slots';
    }

    public static function label(): string
    {
        return 'Get Free Slots (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Reads the free times of a cal.com event type, for a flow that suggests appointments instead of only reporting them.';
    }

    public static function group(): string
    {
        return 'cal.com';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'event_type_id',
                'label' => 'Event type (ID)',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The numeric ID of the cal.com event type. Use the ID and not the slug: the slug sits in the booking URL and gets changed when somebody tidies that URL, at which point this node silently returns nothing.',
            ],
            [
                'handle' => 'start',
                'label' => 'From',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Start of the window, as a date or an ISO 8601 timestamp, for example 2026-09-01 or {{ now }}.',
            ],
            [
                'handle' => 'end',
                'label' => 'Until',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'End of the window, as a date or an ISO 8601 timestamp. cal.com refuses a window without both ends.',
            ],
            [
                'handle' => 'time_zone',
                'label' => 'Time zone',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional, for example Europe/Berlin. Set it and the times come back in that zone, which is what belongs in a mail. Leave it empty and they come back in UTC, which is what belongs in a comparison.',
            ],
            [
                'handle' => 'limit',
                'label' => 'How many at most',
                'type' => 'number',
                'required' => false,
                'default' => self::DEFAULT_LIMIT,
                'help' => 'How many of the earliest free times end up in the context. Two weeks of a 20 minute event type are several hundred, and all of them would land in the run log and in whatever mail uses them.',
            ],
        ];
    }

    /**
     * Was dieser Knoten nach unten weitergibt.
     *
     * `slots` ist die flache, aufsteigend sortierte Liste der Anfangszeiten,
     * das, was eine Mail einsetzt. `by_date` ist dieselbe Auskunft nach Tagen
     * gruppiert, fuer eine Mail, die nach Tagen gliedert.
     *
     * `count` zaehlt die **weitergegebenen** Zeiten, also nach der Grenze, und
     * `total` die, die cal.com im Zeitraum kennt. Die beiden auseinander zu
     * halten ist der Unterschied zwischen "es gibt nur drei Termine" und "wir
     * zeigen drei".
     *
     * `first` ist der fruehste Vorschlag und gehoert **nicht** in einen
     * Anlage-Knoten: er ist bei jedem Lauf ein anderer, und genau daran
     * entsteht der doppelte Termin (siehe Klassenkopf). Auf dem leeren Pfad ist
     * er `null`.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'slots' => 'array',
            'by_date' => 'array',
            'count' => 'integer',
            'total' => 'integer',
            'first' => 'string',
            'event_type_id' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $eventTypeId = $config['event_type_id'] ?? null;
        $start = $config['start'] ?? null;
        $end = $config['end'] ?? null;

        // Alles drei ist statische Konfiguration und wird vor allem anderen
        // geprueft. Ein Knoten ohne Terminart oder ohne Zeitraum ist falsch
        // eingerichtet, und ein Testlauf ist dafuer da, das zu sagen.
        if (! is_string($eventTypeId) && ! is_int($eventTypeId)) {
            return ActionResult::failed('An event type ID is required.');
        }

        $eventTypeId = trim((string) $eventTypeId);

        if ($eventTypeId === '') {
            return ActionResult::failed('An event type ID is required.');
        }

        if (! is_string($start) || trim($start) === '') {
            return ActionResult::failed('A start of the window is required.');
        }

        if (! is_string($end) || trim($end) === '') {
            return ActionResult::failed('An end of the window is required.');
        }

        // Kein Testmodus-Zweig, und das ist Absicht: siehe den Klassenkopf.
        // Diese Aktion liest, sie aendert drueben nichts, und eine Vorschau aus
        // erfundenen Zeiten waere nichts wert.

        if (! $this->client->isConfigured()) {
            return ActionResult::failed('cal.com is not configured: set the API key before using this action.');
        }

        $query = [
            'eventTypeId' => $eventTypeId,
            'start' => trim($start),
            'end' => trim($end),
        ];

        $timeZone = $config['time_zone'] ?? null;

        if (is_string($timeZone) && trim($timeZone) !== '') {
            $query['timeZone'] = trim($timeZone);
        }

        $result = $this->client->slots($query);

        if (! $result->ok) {
            return ActionResult::failed($result->error ?? 'Reading the cal.com slots failed.');
        }

        $byDate = $this->byDate($result->data);
        $all = $this->flatten($byDate);

        // Erst die Frage, die vor der Gegenprobe kommt: **hat cal.com wirklich
        // nichts geschickt, oder haben wir es nur nicht ausgepackt?**
        //
        // `byDate()` uebergeht still alles, was nicht die erwartete Form hat.
        // Ohne diese Unterscheidung faellt eine Formaenderung an `/v2/slots`
        // (ein umbenanntes `start`, eine flache Liste statt der
        // Datums-Schluessel) in denselben Topf wie ein voller Kalender: die
        // Gegenprobe fande die Terminart, der Knoten ginge gruen mit `count`
        // gleich 0, und der Ablauf schluege monatelang nichts vor. Das ist
        // derselbe stille Fehler wie der, gegen den die Gegenprobe unten
        // geschrieben ist, nur eine Ebene tiefer.
        if ($all === [] && $result->data !== []) {
            return ActionResult::failed(
                'cal.com answered with free times that this node could not read. Its answer was not empty, but nothing '
                    .'in it had the shape the slots endpoint answers in (a date, and under it entries carrying a start). '
                    .'Reporting no free times here would be wrong, so this goes red instead.',
                ['event_type_id' => $eventTypeId, 'count' => 0],
            );
        }

        if ($all === []) {
            // Der stille Fehler: eine unbekannte Terminart antwortet genauso
            // wie ein voller Kalender. Erst diese Gegenprobe macht aus der
            // leeren Antwort einen Beleg.
            $eventType = $this->client->eventType($eventTypeId);

            if ($eventType->isNotFound()) {
                return ActionResult::failed(
                    "cal.com has no event type with ID {$eventTypeId}. It answers an unknown event type with an empty list "
                        .'and status 200, exactly like a fully booked calendar, so this would otherwise have looked like "no free times".',
                    ['event_type_id' => $eventTypeId, 'count' => 0],
                );
            }

            // Die Gegenprobe selbst ist fehlgeschlagen (Netz, Schluessel,
            // Rechteumfang). Dann ist nicht belegt, dass der Kalender voll ist,
            // und "wir wissen es nicht" als "nichts frei" auszugeben waere
            // genau der Fehler, den diese Pruefung verhindern soll.
            if (! $eventType->ok) {
                return ActionResult::failed(
                    'cal.com returned no free times, and the check whether event type '
                        ."{$eventTypeId} exists at all did not go through either, so the empty answer proves nothing: "
                        .($eventType->error ?? 'the event type could not be read.'),
                    ['event_type_id' => $eventTypeId, 'count' => 0],
                );
            }
        }

        $limit = $this->limit($config);
        $shown = array_slice($all, 0, $limit);

        return ActionResult::success([
            'slots' => $shown,
            'by_date' => $this->regroup($shown, $byDate),
            'count' => count($shown),
            'total' => count($all),
            'first' => $shown[0] ?? null,
            'event_type_id' => $eventTypeId,
        ]);
    }

    /**
     * cal.coms Antwort in "Datum => Liste von Anfangszeiten" bringen.
     *
     * Die Antwort ist `{"2026-09-01": [{"start": "…"}], …}`. Alles, was nicht
     * dieser Form entspricht, wird uebergangen statt zu einem `null` im
     * Ergebnis zu werden: ein leerer Eintrag in einer Terminliste ist in einer
     * Mail schlimmer als ein fehlender.
     *
     * @param  array<mixed>  $data
     * @return array<string, list<string>>
     */
    protected function byDate(array $data): array
    {
        $out = [];

        foreach ($data as $date => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            $starts = [];

            foreach ($entries as $entry) {
                $start = is_array($entry) ? ($entry['start'] ?? null) : null;

                if (is_string($start) && $start !== '') {
                    $starts[] = $start;
                }
            }

            if ($starts !== []) {
                $out[(string) $date] = $starts;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $byDate
     * @return list<string>
     */
    protected function flatten(array $byDate): array
    {
        $all = array_merge(...array_values($byDate ?: [[]]));

        // `sort` ordnet die Schluessel selbst neu; das Ergebnis ist bereits
        // eine Liste.
        sort($all);

        return $all;
    }

    /**
     * Die Gruppierung nach Tagen auf das zusammenstreichen, was die Grenze
     * uebrig gelassen hat.
     *
     * Ohne diesen Schritt zeigte `by_date` mehr Termine als `slots`, und eine
     * Mail, die nach Tagen gliedert, schluege Zeiten vor, die der Knoten
     * ausdruecklich abgeschnitten hat.
     *
     * @param  list<string>  $shown
     * @param  array<string, list<string>>  $byDate
     * @return array<string, list<string>>
     */
    protected function regroup(array $shown, array $byDate): array
    {
        $keep = array_flip($shown);
        $out = [];

        foreach ($byDate as $date => $starts) {
            $kept = array_values(array_filter($starts, fn (string $s) => isset($keep[$s])));

            if ($kept !== []) {
                $out[$date] = $kept;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function limit(array $config): int
    {
        $limit = $config['limit'] ?? null;

        // Eine 0 oder eine negative Zahl heisst hier nicht "keine", sondern
        // "hier steht Unsinn". Sie durchzureichen ergaebe einen Knoten, der
        // gruen wird und nichts weitergibt, und das ist der Zustand, den der
        // ganze Rest dieser Klasse zu vermeiden versucht.
        return is_numeric($limit) && (int) $limit > 0
            ? (int) $limit
            : self::DEFAULT_LIMIT;
    }
}
