<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns;

use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowEvents;

/**
 * Macht aus VocalFlows Nutzlast eine Oberflaeche, an die ein Folgeknoten
 * anschliessen kann.
 *
 * VocalFlow schickt pro Ereignis einen Umschlag mit vier Ebenen, und ein guter
 * Teil davon ist Buchhaltung des Versenders: `metadata.model_type`
 * (`App\Models\Session`), `metadata.signature_method`,
 * `metadata.delivery_attempt`, `metadata.subscription_id`. Wer das einfach
 * durchreicht, hat keinen Auslöser gebaut, sondern eine Blackbox, in der jeder
 * Folgeknoten selbst graben muss.
 *
 * Was hier flach und lesbar wird, ist die Auswahl, an der ein Ablauf
 * tatsaechlich haengt. Die Rohnutzlast bleibt daneben unter `vocalflow.data`
 * und `vocalflow.metadata` erreichbar, damit der seltene Fall, den diese
 * Auswahl nicht trifft, kein Grund ist, das Addon zu verlassen.
 *
 * ## Vier Stellen, an denen VocalFlows Nutzlast anders ist, als sie aussieht
 *
 * **`data` ist verschachtelt, nicht flach.** Es gibt kein `data.student_id`.
 * Wer danach greift, bekommt immer `null` — und genau dieser Griff hat auf
 * adriangoldner.com dazu gefuehrt, dass die Verarbeitung monatelang still ins
 * Leere lief. Richtig ist `data.session.student_id` beziehungsweise
 * `data.task.student_id`, daneben `data.student` als eigenes Objekt.
 *
 * **`student.id` und `session.student_id` sind zwei verschiedene Kennungen.**
 * `data.student.id` ist die laufende Nummer des Benutzerkontos (`users.id`,
 * eine ganze Zahl), `data.session.student_id` ist dessen UUID
 * (`users.uuid`, eine Zeichenkette). Sie heissen fast gleich, sind aber nicht
 * austauschbar, und wer die eine an eine Stelle schreibt, die die andere
 * erwartet, bekommt keinen Fehler, sondern einen leeren Treffer.
 *
 * Deshalb sind sie hier zusammengezogen und umbenannt: die Person traegt beide,
 * als `id` (Zahl) und `uuid` (Zeichenkette), und in `session` und `task` steht
 * gar keine Personen-Kennung mehr. Ein Feld, dessen Name in die Irre fuehrt,
 * unveraendert weiterzureichen hiesse, die Falle weiterzugeben statt sie
 * zuzumachen.
 *
 * Was ein Ablauf ohnehin meistens braucht, ist keins von beiden, sondern
 * `student.email`: die Partner-API von VocalFlow adressiert Studenten ueber die
 * Adresse, und die beiden Aktionen dieses Anschlusses tun das auch.
 *
 * **`task.student_id` fehlt bei `task.created`.** VocalFlow legt es nur bei
 * `task.assigned` in das `task`-Objekt. `student.uuid` ist bei `task.created`
 * deshalb leer, `student.email` aber gefuellt. Ein Ablauf, der eine Person
 * ansprechen will, nimmt die Adresse.
 *
 * **Der Zustand beim Anlegen ist nicht `scheduled`.** `session.created` feuert
 * an `Session::created`, also bei jedem entstehenden Datensatz: der
 * Spalten-Default ist `draft`, und der Import von Alt-Sitzungen legt sie direkt
 * mit `completed` an. Deshalb tragen die beiden Session-Auslöser einen
 * `status`-Filter, und wer sie benutzt, setzt ihn.
 *
 * **`session.status` heisst bei VocalFlow `flow_status`.** In der Nutzlast
 * steht der Schluessel `status`, im Modell `flow_status`; die moeglichen Werte
 * sind `scheduled`, `in-progress`, `processing`, `review-ready`, `completed`,
 * `cancelled`, `no-show`. Mit Bindestrich, nicht mit Unterstrich, was jede
 * Bedingung betrifft, die darauf vergleicht.
 */
trait FlattensVocalFlowEvents
{
    // --- die Sitzung --------------------------------------------------------

    /**
     * Die Session, flach.
     *
     * @return array<string, mixed>
     */
    protected function sessionOf(object|array $event): array
    {
        $session = $this->branch($event, 'session');
        $type = $this->branch($event, 'session_type');

        return [
            'id' => $this->str($session['id'] ?? null),
            'status' => $this->str($session['status'] ?? null),

            'scheduled_at' => $this->date($session['scheduled_at'] ?? null),
            'completed_at' => $this->date($session['completed_at'] ?? null),

            // Kein `updated_at`. VocalFlow legt es nur bei `session.updated`
            // bei, und dafuer gibt es hier keinen Auslöser: das Feld traegt auf
            // **keinem** unterstuetzten Ereignis je etwas. Es trotzdem
            // anzubieten hiesse, im Datenpicker ein Feld zu zeigen, das zur
            // Laufzeit immer leer ist.
            'created_at' => $this->date($session['created_at'] ?? null),

            'duration_minutes' => $this->int($session['duration_minutes'] ?? null),
            'rating' => $this->int($session['rating'] ?? null),

            // Die Terminart liegt bei VocalFlow zweimal: als Kennung am
            // `session`-Objekt und als eigenes Objekt daneben. Beide sind hier
            // erreichbar, weil die Kennung filtert und der Name in einer Mail
            // steht. Das eigene Objekt fehlt bei einigen Ereignissen ganz,
            // `session_type_id` traegt dann noch.
            'session_type_id' => $this->str($session['session_type_id'] ?? null)
                ?? $this->str($type['id'] ?? null),
            'session_type_name' => $this->str($type['name'] ?? null),
            'session_type_slug' => $this->str($type['slug'] ?? null),

            // Nur bei `session.created` mitgeschickt; bei `session.completed`
            // laesst VocalFlow es weg. Steht trotzdem immer im Schema, damit
            // eine Vorlage nicht auf ein Feld trifft, das mal da ist und mal
            // fehlt.
            'session_type_duration_minutes' => $this->int($type['duration_minutes'] ?? null),
        ];
    }

    /**
     * Was VocalFlow ueber den Abschluss der Session mitschickt.
     *
     * Nur bei `session.completed` gefuellt. Steht als eigener Zweig und nicht
     * in `session`, weil es Bewertung des Vorgangs ist und nicht Zustand der
     * Session: `completion_quality` ist VocalFlows Urteil, `rating` ist die
     * Zahl, die jemand vergeben hat.
     *
     * @return array<string, mixed>
     */
    protected function completionOf(object|array $event): array
    {
        $completion = $this->branch($event, 'completion_data');

        return [
            'quality' => $this->str($completion['completion_quality'] ?? null),
            'follow_up_required' => $this->bool($completion['follow_up_required'] ?? null),
            'referral_eligible' => $this->bool($completion['referral_eligible'] ?? null),
            'tasks_assigned' => $this->int($completion['tasks_assigned'] ?? null),
        ];
    }

    // --- die Aufgabe --------------------------------------------------------

    /**
     * Die Aufgabe, flach.
     *
     * @return array<string, mixed>
     */
    protected function taskOf(object|array $event): array
    {
        $task = $this->branch($event, 'task');

        return [
            'id' => $this->str($task['id'] ?? null),
            'title' => $this->str($task['title'] ?? null),
            'description' => $this->str($task['description'] ?? null),

            // `type` gibt es nur bei `task.assigned`. Werte sind unter anderem
            // `exercise`, `homework`, `vocal-exercise`, `practice-song` — mit
            // Bindestrich, was jede Bedingung betrifft, die darauf vergleicht.
            'type' => $this->str($task['type'] ?? null),

            'status' => $this->str($task['status'] ?? null),
            'priority' => $this->str($task['priority'] ?? null),

            'due_date' => $this->date($task['due_date'] ?? null),
            'created_at' => $this->date($task['created_at'] ?? null),
            'updated_at' => $this->date($task['updated_at'] ?? null),
            'assigned_at' => $this->date($task['assigned_at'] ?? null),

            'estimated_duration_minutes' => $this->int($task['estimated_duration_minutes'] ?? null),

            // Die Session, zu der die Aufgabe gehoert, als Kennung. Das
            // dazugehoerige Objekt steht unter `session` daneben.
            'session_id' => $this->str($task['session_id'] ?? null),
        ];
    }

    /**
     * Die Session, zu der eine Aufgabe gehoert.
     *
     * VocalFlow legt sie bei Aufgaben-Ereignissen nur in Kurzform bei: Kennung,
     * Termin, Zustand. Sie traegt hier denselben Schluessel wie bei den
     * Session-Auslösern, aber ein kleineres Schema, und das Schema des
     * Auslösers sagt jeweils welches. Ihr einen anderen Namen zu geben hiesse,
     * einen Ablauf, der beides verarbeitet, zwei Namen fuer dieselbe Sache
     * lernen zu lassen.
     *
     * @return array<string, mixed>
     */
    protected function taskSessionOf(object|array $event): array
    {
        $session = $this->branch($event, 'session');

        return [
            'id' => $this->str($session['id'] ?? null),
            'scheduled_at' => $this->date($session['scheduled_at'] ?? null),
            'status' => $this->str($session['status'] ?? null),
        ];
    }

    /**
     * Was sich geaendert hat, als Karte "Feld => neuer Wert, als Text".
     *
     * VocalFlow schickt bei `task.updated` und `session.updated` ein
     * `data.changes` in der Form `{"status": {"from": "assigned", "to":
     * "completed"}}`. Hier stehen die beiden Seiten getrennt, damit eine
     * Bedingung `changed_to.status ist gleich completed` ohne Umweg
     * funktioniert — das ist der Ablauf, den man mit diesem Auslöser fast immer
     * bauen will.
     *
     * Was nicht in Text zu verwandeln ist, faellt weg. Ein Feld, das mal Text
     * und mal Struktur ist, ist in einer Vorlage nicht benutzbar.
     *
     * @return array{fields: array<int, string>, changed_from: array<string, string>, changed_to: array<string, string>}
     */
    protected function changesOf(object|array $event): array
    {
        $changes = $this->branch($event, 'changes');

        $from = [];
        $to = [];

        $fields = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $field = (string) $field;
            $fields[] = $field;

            // `array_key_exists` und nicht `?? null`, und die leere Zeichenkette
            // statt eines fehlenden Schluessels.
            //
            // Der Unterschied ist der zwischen "geleert" und "nicht geaendert".
            // Wer eine Faelligkeit streicht, schickt `{"to": null}`. Faellt der
            // Schluessel dann weg, sieht `changed_to` genauso aus wie bei einem
            // Feld, das gar nicht in der Aenderungsliste stand — und eine
            // Bedingung "Faelligkeit gestrichen" laesst sich nicht mehr bauen.
            // Dieselbe Ueberlegung wie bei `bool()`: "nicht mitgeschickt" und
            // "auf nichts gesetzt" sind verschiedene Auskuenfte.
            if (array_key_exists('from', $change)) {
                $from[$field] = $this->printable($change['from']) ?? '';
            }

            if (array_key_exists('to', $change)) {
                $to[$field] = $this->printable($change['to']) ?? '';
            }
        }

        return [
            // `fields` traegt jedes geaenderte Feld, auch eines, dessen beide
            // Seiten sich nicht in Text verwandeln liessen. Es ist damit die
            // verlaessliche Antwort auf "hat sich X geaendert", waehrend die
            // beiden Karten die Antwort auf "worauf" sind.
            'fields' => array_values(array_unique($fields)),
            'changed_from' => $from,
            'changed_to' => $to,
        ];
    }

    // --- Personen -----------------------------------------------------------

    /**
     * Der Student, mit beiden Kennungen.
     *
     * Die UUID steht bei VocalFlow nicht am `student`-Objekt, sondern am
     * Vorgang (`session.student_id`, `task.student_id`). Sie wird hier
     * hierhergezogen, weil sie zur Person gehoert und nicht zur Session, und
     * weil `session.student_id` neben `student.id` genau die Verwechslung
     * anbietet, die dieser Flattener zumachen soll.
     *
     * @return array<string, mixed>
     */
    protected function studentOf(object|array $event, string $entity): array
    {
        return $this->personOf(
            $this->branch($event, 'student'),
            $this->branch($event, $entity)['student_id'] ?? null,
        );
    }

    /**
     * Der Coach, nach derselben Regel.
     *
     * **Bei Aufgaben ohne UUID.** VocalFlow legt `coach_id` nur an das
     * `session`-Objekt, nie an das `task`-Objekt; der Coach kommt dort ueber
     * die Session der Aufgabe, und die Kurzform, die mitgeschickt wird, traegt
     * nur Kennung, Termin und Zustand. Auf einer Aufgaben-Nutzlast waere
     * `coach.uuid` deshalb **immer** leer, und ein Feld, das der Datenpicker
     * anbietet und das auf keinem echten Ereignis je etwas traegt, ist
     * schlimmer als ein fehlendes: es faellt nicht auf, weil eine leere Zeile
     * in einer Mail kein Fehler ist.
     *
     * @return array<string, mixed>
     */
    protected function coachOf(object|array $event, string $entity): array
    {
        return $this->personOf(
            $this->branch($event, 'coach'),
            $this->branch($event, $entity)['coach_id'] ?? null,
            withUuid: $entity !== 'task',
        );
    }

    /**
     * Eine Person, immer mit denselben Schluesseln.
     *
     * Immer alle, auch wenn VocalFlow sie nicht mitgeschickt hat: ein Feld, das
     * mal da ist und mal fehlt, zwingt jeden Folgeknoten zu einer Fallabfrage,
     * und eine Mail-Vorlage, die auf ein fehlendes Feld trifft, bricht.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    protected function personOf(array $person, mixed $uuid, bool $withUuid = true): array
    {
        $name = $this->str($person['name'] ?? null);

        return [
            // Die laufende Nummer des Kontos. Ganze Zahl.
            'id' => $this->int($person['id'] ?? null),

            // Die UUID desselben Kontos. Zeichenkette. Das ist der Wert, mit
            // dem VocalFlow intern verknuepft; `id` ist es nicht.
            ...($withUuid ? ['uuid' => $this->str($uuid)] : []),

            'name' => $name,

            // "Hallo Nina" ist die haeufigste Anrede in einer Mail. VocalFlow
            // fuehrt keinen Vornamen, also wird er wie bei cal.com am ersten
            // Leerzeichen abgeteilt.
            'first_name' => $this->firstWordOf($name),

            // Der Wert, an dem alles Weitere haengt: die Partner-API adressiert
            // Studenten ueber die Adresse, nicht ueber eine der beiden
            // Kennungen.
            'email' => $this->str($person['email'] ?? null),
        ];
    }

    // --- der Umschlag -------------------------------------------------------

    /**
     * Was VocalFlow geschickt hat, und wann.
     *
     * `data` und `metadata` liegen roh daneben. Das ist die Notausgang-Zeile
     * fuer Felder, die diese Auswahl nicht trifft, und der Grund, warum die
     * Auswahl oben eine Auswahl sein darf statt vollstaendig sein zu muessen.
     *
     * @return array<string, mixed>
     */
    protected function envelopeOf(object|array $event): array
    {
        $metadata = $this->get($event, 'metadata');

        return [
            'event' => $this->str($this->get($event, 'event')),
            'received_at' => $this->date($this->get($event, 'timestamp')),
            'data' => $this->dataOf($event),
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }

    // --- Ausgabeschemata ----------------------------------------------------

    /**
     * Was der Editor als Ausgabe eines Session-Auslösers anzeigt.
     *
     * @return array<string, mixed>
     */
    protected static function sessionOutputSchema(bool $withCompletion = false): array
    {
        // Die Reihenfolge ist nicht Geschmack: der Testfall
        // `every trigger describes exactly what it builds` vergleicht sie
        // gegen die Reihenfolge im gebauten Kontext, damit Schema und Kontext
        // nicht auseinanderlaufen koennen, ohne dass es auffaellt. `completion`
        // steht deshalb direkt hinter `session`, dort wo es auch im Kontext
        // steht und wo es beim Lesen hingehoert.
        $completion = $withCompletion ? [
            'completion' => [
                'quality' => 'string',
                'follow_up_required' => 'boolean',
                'referral_eligible' => 'boolean',
                'tasks_assigned' => 'integer',
            ],
        ] : [];

        return [
            'session' => [
                'id' => 'string',
                'status' => 'string',
                'scheduled_at' => 'string',
                'completed_at' => 'string',
                'created_at' => 'string',
                'duration_minutes' => 'integer',
                'rating' => 'integer',
                'session_type_id' => 'string',
                'session_type_name' => 'string',
                'session_type_slug' => 'string',
                'session_type_duration_minutes' => 'integer',
            ],
            ...$completion,
            'student' => self::personOutputSchema(),
            'coach' => self::personOutputSchema(),
            'vocalflow' => self::envelopeOutputSchema(),
        ];
    }

    /**
     * Was der Editor als Ausgabe eines Aufgaben-Auslösers anzeigt.
     *
     * @return array<string, mixed>
     */
    protected static function taskOutputSchema(bool $withChanges = false): array
    {
        $schema = [
            'task' => [
                'id' => 'string',
                'title' => 'string',
                'description' => 'string',
                'type' => 'string',
                'status' => 'string',
                'priority' => 'string',
                'due_date' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
                'assigned_at' => 'string',
                'estimated_duration_minutes' => 'integer',
                'session_id' => 'string',
            ],
            'student' => self::personOutputSchema(),
            'coach' => self::personOutputSchema(withUuid: false),
            'session' => [
                'id' => 'string',
                'scheduled_at' => 'string',
                'status' => 'string',
            ],
            'vocalflow' => self::envelopeOutputSchema(),
        ];

        if ($withChanges) {
            $schema['fields'] = 'array';
            $schema['changed_from'] = 'array';
            $schema['changed_to'] = 'array';
        }

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    protected static function personOutputSchema(bool $withUuid = true): array
    {
        return [
            'id' => 'integer',
            ...($withUuid ? ['uuid' => 'string'] : []),
            'name' => 'string',
            'first_name' => 'string',
            'email' => 'string',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function envelopeOutputSchema(): array
    {
        return [
            'event' => 'string',
            'received_at' => 'string',
            'data' => 'array',
            'metadata' => 'array',
        ];
    }

    // --- Filter -------------------------------------------------------------

    /**
     * Die Filter, die die beiden Session-Auslöser tragen.
     *
     * Dieselbe Ueberlegung wie beim Terminart-Filter des cal.com-Anschlusses:
     * ein Betrieb fuehrt bei VocalFlow mehrere Sitzungsarten nebeneinander, und
     * ein kostenloses Kennenlernen und eine bezahlte Stunde sind verschiedene
     * Vorgaenge mit verschiedenen Mails. Alle Sitzungsarten feuern dasselbe
     * Ereignis. Ein Ablauf ohne Filter schickt die Nachbereitung an jemanden,
     * der nur schnuppern wollte.
     *
     * **Zwei Felder, weil beide ihren Nachteil haben.** Der Slug ist der Wert,
     * den man in VocalFlow sieht und ohne Nachschlagen eintraegt, aber er
     * aendert sich, wenn jemand die Sitzungsart umbenennt; ein Filter, der
     * daran haengt, faellt dann still aus. Die UUID aendert sich nie, steht
     * aber nirgends, wo man sie einfach abliest.
     *
     * Sind beide gesetzt, muessen beide passen. Der Name ist bewusst keine
     * Filterachse: den aendert man, wenn er in einer Mail besser klingen soll.
     *
     * ## Die dritte Achse: der Zustand
     *
     * Sie sieht bei "Session angelegt" ueberfluessig aus und ist es nicht.
     * `session.created` feuert bei VocalFlow an `Session::created`, also bei
     * **jedem** entstehenden Datensatz — und der Zustand ist dabei keineswegs
     * immer `scheduled`:
     *
     *   - Der Spalten-Default ist `draft`. Ein angefangener und liegen
     *     gelassener Entwurf loest das Ereignis aus.
     *   - Der Import von Alt-Sitzungen legt sie direkt mit `completed` an. Eine
     *     einmalige Migration schickt damit ein `session.created` je
     *     importierter Stunde, alle mit einem Termin in der Vergangenheit.
     *
     * Ein Ablauf "Unterlagen zur Vorbereitung schicken" ohne diesen Filter
     * mailt beim naechsten Import an jeden Studenten einmal pro Altstunde. Der
     * Filter ist dagegen das einzige Mittel, und er gehoert deshalb neben die
     * Sitzungsart und nicht in eine Bedingung, die erst nach dem Start greift.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function sessionTypeFilterSchema(): array
    {
        return [
            [
                'handle' => 'session_type_slug',
                'label' => 'Session type (slug)',
                'type' => 'text',
                'required' => false,
                'help' => 'The VocalFlow session type slug, for example "intro-lesson". Leave empty to match every session type. Note that renaming the session type in VocalFlow changes the slug and stops this filter from matching.',
            ],
            [
                'handle' => 'session_type_id',
                'label' => 'Session type (ID)',
                'type' => 'text',
                'required' => false,
                'help' => 'The VocalFlow session type UUID. Survives a renamed session type. Leave empty to match every session type.',
            ],
            [
                'handle' => 'status',
                'label' => 'Status',
                'type' => 'text',
                'required' => false,
                'help' => 'Only run when the session carries this status: scheduled, in-progress, processing, review-ready, completed, cancelled or no-show. Leave empty to match every status. Mind the hyphens.',
            ],
        ];
    }

    /**
     * Die Filter, die die Aufgaben-Auslöser tragen.
     *
     * Zustand und Dringlichkeit, und ausdruecklich **nicht** die Aufgabenart.
     * Die Art (`exercise`, `homework`, `vocal-exercise`) waere die naheliegende
     * Achse, aber VocalFlow legt `type` nur bei `task.assigned` in die Nutzlast
     * und laesst es bei `task.created` und `task.updated` weg. Ein Filter, der
     * auf zwei von drei Auslösern nie passt, faellt still aus, und still
     * ausfallende Filter sind die schlechteste Sorte: der Ablauf laeuft
     * einfach nie, und niemand sucht danach.
     *
     * Zustand und Dringlichkeit stehen dagegen in allen drei Nutzlasten.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function taskFilterSchema(): array
    {
        return [
            [
                'handle' => 'status',
                'label' => 'Status',
                'type' => 'text',
                'required' => false,
                'help' => 'Only run when the task carries this status: assigned, in-progress, completed, overdue or cancelled. Leave empty to match every status. Mind the hyphen in "in-progress".',
            ],
            [
                'handle' => 'priority',
                'label' => 'Priority',
                'type' => 'text',
                'required' => false,
                'help' => 'Only run for this priority: low, medium or high. Leave empty to match every priority.',
            ],
        ];
    }

    // --- Abgleich -----------------------------------------------------------

    /**
     * Ist das ueberhaupt das Ereignis, fuer das dieser Auslöser da ist?
     *
     * Im Normalbetrieb kann das nicht fehlgehen: der Controller schlaegt das
     * Handle in {@see VocalFlowEvents} nach, eine abgeschlossene Session
     * erreicht den Auslöser fuer abgeschlossene Sessions und keinen anderen.
     *
     * Die Pruefung steht trotzdem hier, weil `matches()` oeffentlich ist und
     * nicht nur vom Controller aufgerufen wird: der Testmodus des Editors, ein
     * fremdes Addon und jeder kuenftige zweite Weg in dieselben Auslöser gehen
     * durch dieselbe Methode. Ein Auslöser, der sich darauf verlaesst, dass ihn
     * schon der Richtige ruft, laesst bei einem zweiten Aufrufer alles durch.
     */
    protected function isEvent(object|array $event, string $expected): bool
    {
        return $this->get($event, 'event') === $expected;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function matchesSessionType(object|array $event, array $config): bool
    {
        $type = $this->branch($event, 'session_type');
        $session = $this->branch($event, 'session');

        $slug = $config['session_type_slug'] ?? null;
        $id = $config['session_type_id'] ?? null;
        $status = $config['status'] ?? null;

        if (is_string($slug) && $slug !== '' && ($type['slug'] ?? null) !== $slug) {
            return false;
        }

        if (is_string($status) && $status !== '' && ($session['status'] ?? null) !== $status) {
            return false;
        }

        // Die Kennung steht an zwei Stellen und nicht immer an beiden. Passt
        // eine, ist es die richtige Sitzungsart.
        if (is_scalar($id) && (string) $id !== '') {
            $candidates = [
                $session['session_type_id'] ?? null,
                $type['id'] ?? null,
            ];

            $candidates = array_filter($candidates, fn ($value) => is_scalar($value) && (string) $value !== '');

            if (! in_array((string) $id, array_map('strval', $candidates), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function matchesTaskFilters(object|array $event, array $config): bool
    {
        $task = $this->branch($event, 'task');

        foreach (['status', 'priority'] as $field) {
            $wanted = $config[$field] ?? null;

            if (! is_string($wanted) || $wanted === '') {
                continue;
            }

            if (($task[$field] ?? null) !== $wanted) {
                return false;
            }
        }

        return true;
    }

    // --- innere Teile -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function dataOf(object|array $event): array
    {
        $data = $this->get($event, 'data');

        return is_array($data) ? $data : [];
    }

    /**
     * Ein Zweig unter `data`, immer als Array.
     *
     * @return array<string, mixed>
     */
    protected function branch(object|array $event, string $key): array
    {
        $branch = $this->dataOf($event)[$key] ?? null;

        return is_array($branch) ? $branch : [];
    }

    protected function get(object|array $event, string $key): mixed
    {
        return is_array($event) ? ($event[$key] ?? null) : ($event->{$key} ?? null);
    }

    /**
     * Eine Zeichenkette oder null, nie ein Array, nie eine Zahl.
     *
     * VocalFlow fuellt einige dieser Felder je nach Ereignis mit `null` oder
     * laesst sie ganz weg. Ein Feld, das mal Text und mal Struktur ist, ist in
     * einer Mail-Vorlage nicht benutzbar.
     */
    protected function str(mixed $value): ?string
    {
        // `trim` fuer die Pruefung, aber der Wert geht unveraendert durch. Ein
        // Name aus lauter Leerzeichen ist kein Name: `first_name` wuerde `null`
        // und die Anrede in der Mail "Hallo " lauten, ohne dass irgendwo ein
        // Fehler auftaucht. Getrimmt zurueckzugeben waere der naheliegende
        // zweite Schritt und der falsche — dieser Flattener bildet ab, er
        // korrigiert nicht.
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Eine ganze Zahl oder null.
     *
     * Dieselbe Grenze wie bei `str()`, und aus demselben Grund: der
     * Token-Aufloeser macht aus einem Array, das in eine Vorlage geraet, JSON.
     * `{{ student.id }}` schriebe dann `{"x":1}` in die Mail.
     */
    protected function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-')))
            ? (int) $value
            : null;
    }

    /**
     * Ein Wahrheitswert oder null.
     *
     * Ausdruecklich kein Rueckfall auf `false`: "hat VocalFlow nicht
     * mitgeschickt" und "ist nicht noetig" sind verschiedene Auskuenfte, und
     * eine Bedingung, die beides gleich behandelt, verschickt die Nachfassmail
     * an alle oder an niemanden.
     */
    protected function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Ein Zeitpunkt in einer Form.
     *
     * Dasselbe `DATE_ATOM`, das auch die Nachbar-Auslöser liefern. VocalFlow
     * schickt alle Zeitpunkte als ISO-8601 in UTC, `due_date` eingeschlossen —
     * es haengt an einem `datetime`-Cast und geht durch dasselbe
     * `toISOString()` wie der Rest.
     *
     * **Der zweite Parameter ist UTC, und das ist kein Beiwerk.** Er greift
     * nur, wenn die Zeichenkette selbst keine Zone traegt — bei `2026-09-05`
     * etwa. Ohne ihn naehme PHP die Zeitzone der Anwendung, und derselbe Wert
     * ergaebe auf einem deutschen Kunden `2026-09-05T00:00:00+02:00` und auf
     * einem Server in UTC `2026-09-05T00:00:00+00:00`. Eine Erinnerung "am Tag
     * vor der Faelligkeit" spraenge damit je nach Standort ueber die
     * Tagesgrenze, und der Fehler liesse sich in keinem Testlauf sehen, weil
     * Testumgebungen in UTC laufen.
     *
     * Was sich nicht lesen laesst, geht unveraendert durch: ein unlesbarer
     * Zeitpunkt ist immer noch besser als gar keiner.
     */
    protected function date(mixed $value): ?string
    {
        $value = $this->str($value);

        if ($value === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Das erste Wort eines Namens.
     */
    protected function firstWordOf(mixed $name): ?string
    {
        $name = $this->str($name);

        if ($name === null) {
            return null;
        }

        // `strtok` antwortet `false`, wenn der Name nur aus Leerzeichen
        // besteht; alles andere ist ein nicht-leeres erstes Wort.
        $first = strtok($name, ' ');

        return is_string($first) ? $first : null;
    }

    /**
     * Ein Wert als druckbarer Text, oder null.
     */
    protected function printable(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            // Nur eine **Liste** wird eine Zeile. Eine Karte faellt weg, und der
            // Unterschied ist nicht akademisch: `['a', 'b']` zu `"a, b"`
            // zusammenzuziehen verliert nichts, `['farbe' => 'rot']` zu `"rot"`
            // dagegen verliert den Schluessel und laesst einen Wert
            // zurueck, der wie ein echter aussieht. In einer Aenderungsliste
            // stuende dann `changed_to.einstellungen = "rot"`, und eine
            // Bedingung darauf vergleicht gegen etwas, das es nie gab.
            //
            // Weg heisst hier `null` und damit "kein Wert", was ein
            // Folgeknoten behandeln kann. Ein falscher Wert kann er nicht.
            if (! array_is_list($value)) {
                return null;
            }

            $parts = array_filter(array_map(
                fn ($item) => is_scalar($item) ? (string) $item : null,
                $value,
            ), fn ($item) => is_string($item) && $item !== '');

            return $parts === [] ? null : implode(', ', $parts);
        }

        return null;
    }
}
