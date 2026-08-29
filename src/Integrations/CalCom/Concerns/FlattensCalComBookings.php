<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Concerns;

use Goldnead\StatamicAutomations\Integrations\CalCom\CalComEvents;

/**
 * Macht aus cal.coms Nutzlast eine Oberflaeche, an die ein Folgeknoten
 * anschliessen kann.
 *
 * cal.com schickt pro Buchung rund vierzig Felder in drei Ebenen, viele davon
 * fuer den Betrieb einer Kalender-App und nicht fuer einen Ablauf: `iCalUID`,
 * `iCalSequence`, `appsStatus`, `conferenceData.createRequest.requestId`,
 * `seatsShowAvailabilityCount`. Wer die einfach durchreicht, hat keinen
 * Auslöser gebaut, sondern eine Blackbox, in der jeder Folgeknoten selbst
 * graben muss.
 *
 * Was hier flach und lesbar wird, ist die Auswahl, an der ein Ablauf
 * tatsaechlich haengt. Die Rohnutzlast bleibt daneben unter `cal_com.payload`
 * erreichbar, damit der seltene Fall, den diese Auswahl nicht trifft, kein
 * Grund ist, das Addon zu verlassen.
 *
 * ## Fuenf Stellen, an denen cal.coms Nutzlast anders ist, als sie aussieht
 *
 * **`type` ist der Slug, `eventTitle` der Titel.** `payload.type` heisst nicht
 * "Art des Ereignisses", sondern ist der Slug der Terminart
 * (`"erstgespraech"`); der lesbare Titel steht in `payload.eventTitle`
 * (`"Erstgespraech"`). Ein Feld `payload.eventType` gibt es nicht. Beide werden
 * hier getrennt gefuehrt, weil der Slug filtert und der Titel in einer Mail
 * steht.
 *
 * **`payload.title` ist der Titel der Buchung, nicht der Terminart.** Er lautet
 * "Erstgespraech zwischen A und B" und ist damit ein anderer Text als
 * `eventTitle`.
 *
 * **`language` ist ein Objekt.** `attendees[].language` ist `{"locale": "de"}`
 * und keine Zeichenkette. Wer das Objekt in eine Mail schreibt, bekommt
 * "Array".
 *
 * **Die Zeitschreibweise wechselt je Ereignis.** `BOOKING_CREATED` liefert
 * `2026-09-03T10:00:00Z`, `BOOKING_CANCELLED` `2026-09-03T10:00:00+00:00`,
 * `BOOKING_REJECTED` `2026-09-05T16:00:00.000Z`. Alle drei sind UTC und alle
 * drei meinen denselben Zeitpunkt. Hier werden sie auf eine Form gebracht,
 * dieselbe, die auch die Nachbar-Auslöser liefern. Sonst faende eine Bedingung
 * `starts_at ist gleich ...` denselben Termin auf dem einen Ereignis und auf
 * dem anderen nicht, und dieselbe Mail-Vorlage druckte drei verschiedene Texte
 * fuer denselben Moment.
 *
 * **`location` ist eine Maschinenkennung, keine Ortsangabe.** Bei einem
 * Videotermin steht dort `integrations:daily`, bei einem Termin vor Ort die
 * echte Adresse. In eine Mail gehoert `meeting_url`, nicht `location`.
 */
trait FlattensCalComBookings
{
    /**
     * Die Buchung, flach.
     *
     * @return array<string, mixed>
     */
    protected function bookingOf(object|array $event): array
    {
        $payload = $this->payloadOf($event);
        $answers = $this->answersOf($payload);
        $attendees = $this->attendeesOf($payload, $answers);

        return [
            'uid' => $this->str($payload['uid'] ?? null),
            'id' => $this->int($payload['bookingId'] ?? null),
            'title' => $this->str($payload['title'] ?? null),

            // Zwei verschiedene Texte, und die Verwechslung ist die
            // naheliegende: `description` ist die Beschreibung, die an der
            // Buchung haengt (cal.com faellt dafuer selbst schon auf
            // `additionalNotes` zurueck), `notes` ist ausdruecklich das, was
            // der Bucher ins Feld "Weitere Anmerkungen" getippt hat. Fuer eine
            // Vorbereitungsmail ist `notes` das interessante Feld.
            'description' => $this->str($payload['description'] ?? null),
            'notes' => $this->str($payload['additionalNotes'] ?? null),

            'status' => $this->str($payload['status'] ?? null),

            'starts_at' => $this->date($payload['startTime'] ?? null),
            'ends_at' => $this->date($payload['endTime'] ?? null),
            'duration_minutes' => $this->int($payload['length'] ?? null),

            'event_type_slug' => $this->str($payload['type'] ?? null),
            'event_type_title' => $this->str($payload['eventTitle'] ?? null),
            'event_type_id' => $this->int($payload['eventTypeId'] ?? null),

            // cal.com fuehrt den Preis in der kleinsten Waehrungseinheit:
            // `9000` sind 90,00 EUR. Der Name sagt das, weil eine Mail, die
            // "9000 EUR" druckt, sonst erst beim Empfaenger auffaellt. Bei
            // einer Absage schickt cal.com hier `null`, nicht `0`.
            'price_cent' => $this->int($payload['price'] ?? null),
            'currency' => $this->str($payload['currency'] ?? null),

            'location' => $this->str($payload['location'] ?? null),
            'meeting_url' => $this->meetingUrlOf($payload),

            // Der erste Teilnehmer ist der, der gebucht hat; alles Weitere sind
            // Gaeste, die er hinzugefuegt hat. Drei Formen, weil drei
            // verschiedene Knoten daran haengen: die flache fuer den Normalfall
            // "schreib dem, der gebucht hat", die Liste fuer eine Schleife, und
            // die Adressenzeile fuer das `to`-Feld der Mail-Aktion, das ein
            // einzelnes Textfeld ist und keine Liste annimmt.
            'attendee' => $attendees[0] ?? $this->emptyPerson(),
            'attendees' => $attendees,
            'attendee_emails' => $this->emailLineOf($attendees),
            'attendees_count' => count($attendees),
            'organizer' => $this->organizerOf($payload),

            // Die drei Gruende stehen einzeln, weil cal.com sie in drei
            // verschiedene Felder legt und weil der Auslöser selbst schon sagt,
            // welches gemeint ist: an "Buchung abgesagt" haengt
            // `cancellation_reason`, an "Buchung abgelehnt"
            // `rejection_reason`.
            'cancellation_reason' => $this->str($payload['cancellationReason'] ?? null),
            'rejection_reason' => $this->str($payload['rejectionReason'] ?? null),
            'reschedule_reason' => $answers['rescheduleReason'] ?? null,

            // Eine Verlegung ist bei cal.com keine geaenderte Buchung, sondern
            // eine neue: `uid` oben ist die neue, `rescheduled_from_uid` die
            // abgesagte alte, und `rescheduled_from_starts_at` ist der Termin,
            // der vorher stand. Ohne das letzte Feld kann eine Mail nicht
            // sagen, wovon der Termin verlegt wurde.
            'rescheduled_from_uid' => $this->str($payload['rescheduleUid'] ?? null),
            'rescheduled_from_starts_at' => $this->date($payload['rescheduleStartTime'] ?? null),
            'rescheduled_from_ends_at' => $this->date($payload['rescheduleEndTime'] ?? null),

            // Alles, was im Buchungsformular beantwortet wurde, unter dem
            // Schluessel des Feldes und als druckbarer Text. Ohne das waeren
            // eigene Felder ("Welcher Chor?", "Stimmlage") nur ueber die
            // Rohnutzlast erreichbar, und genau die soll dieser Auslöser
            // erspart machen.
            'answers' => $answers,
        ];
    }

    /**
     * Der Umschlag: was cal.com geschickt hat, und wann.
     *
     * `payload` liegt roh daneben. Das ist die Notausgang-Zeile fuer Felder,
     * die diese Auswahl nicht trifft, und der Grund, warum die Auswahl oben
     * eine Auswahl sein darf statt vollstaendig sein zu muessen.
     *
     * @return array<string, mixed>
     */
    protected function envelopeOf(object|array $event): array
    {
        return [
            'trigger_event' => $this->str($this->get($event, 'triggerEvent')),
            'received_at' => $this->date($this->get($event, 'createdAt')),
            'payload' => $this->payloadOf($event),
        ];
    }

    /**
     * Was der Editor als Ausgabe eines cal.com-Auslösers anzeigt.
     *
     * @return array<string, mixed>
     */
    protected static function calComOutputSchema(): array
    {
        return [
            'booking' => [
                'uid' => 'string',
                'id' => 'integer',
                'title' => 'string',
                'description' => 'string',
                'notes' => 'string',
                'status' => 'string',
                'starts_at' => 'string',
                'ends_at' => 'string',
                'duration_minutes' => 'integer',
                'event_type_slug' => 'string',
                'event_type_title' => 'string',
                'event_type_id' => 'integer',
                'price_cent' => 'integer',
                'currency' => 'string',
                'location' => 'string',
                'meeting_url' => 'string',
                'attendee' => self::personOutputSchema(),
                'attendees' => 'array',
                'attendee_emails' => 'string',
                'attendees_count' => 'integer',
                'organizer' => self::personOutputSchema(),
                'cancellation_reason' => 'string',
                'rejection_reason' => 'string',
                'reschedule_reason' => 'string',
                'rescheduled_from_uid' => 'string',
                'rescheduled_from_starts_at' => 'string',
                'rescheduled_from_ends_at' => 'string',
                'answers' => 'array',
            ],
            'cal_com' => [
                'trigger_event' => 'string',
                'received_at' => 'string',
                'payload' => 'array',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function personOutputSchema(): array
    {
        return [
            'name' => 'string',
            'first_name' => 'string',
            'email' => 'string',
            'timezone' => 'string',
            'language' => 'string',
            'username' => 'string',
            'phone' => 'string',
        ];
    }

    /**
     * Die Filter, die alle fuenf Auslöser tragen.
     *
     * Dieselbe Ueberlegung wie beim Endpunkt-Filter des Booking-Addons: ein
     * Betrieb fuehrt bei cal.com mehrere Terminarten nebeneinander, und ein
     * kostenloses Erstgespraech und eine bezahlte Stunde sind verschiedene
     * Vorgaenge mit verschiedenen Mails. Alle Terminarten feuern denselben
     * Webhook. Ein Ablauf ohne Filter schickt die Rechnungs-Mail an jemanden,
     * der ein Erstgespraech gebucht hat.
     *
     * **Zwei Felder, weil beide ihren Nachteil haben.** Der Slug ist der Wert,
     * den man im cal.com-Konto sieht und ohne Nachschlagen eintragen kann, aber
     * er steckt in der Buchungs-URL und wird geaendert, wenn die URL
     * huebscher werden soll. Die Nummer aendert sich nie, steht aber nirgends,
     * wo man sie einfach abliest. Wer den Ablauf ueber Jahre stabil haben will,
     * nimmt die Nummer; wer ihn schnell aufsetzen will, den Slug.
     *
     * Sind beide gesetzt, muessen beide passen. Der Titel ist bewusst keine
     * Filterachse: den aendert man, wenn er in einer Mail besser klingen soll.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function eventTypeFilterSchema(): array
    {
        return [
            [
                'handle' => 'event_type_slug',
                'label' => 'Event type (slug)',
                'type' => 'text',
                'required' => false,
                'help' => 'The cal.com event type slug, for example "intro-call". Leave empty to match every event type. Note that renaming the slug in cal.com stops this filter from matching.',
            ],
            [
                'handle' => 'event_type_id',
                'label' => 'Event type (ID)',
                'type' => 'text',
                'required' => false,
                'help' => 'The numeric cal.com event type ID. Survives a renamed slug. Leave empty to match every event type.',
            ],
        ];
    }

    /**
     * Ist das ueberhaupt das Ereignis, fuer das dieser Auslöser da ist?
     *
     * Im Normalbetrieb kann das nicht fehlgehen: der Controller schlaegt das
     * Handle in {@see CalComEvents}
     * nach, eine Absage erreicht den Auslöser fuer Absagen und keinen anderen.
     *
     * Die Pruefung steht trotzdem hier, weil `matches()` oeffentlich ist und
     * nicht nur vom Controller aufgerufen wird: der Testmodus des Editors, ein
     * fremdes Addon und jeder kuenftige zweite Weg in dieselben Auslöser gehen
     * durch dieselbe Methode. Ein Auslöser, der sich darauf verlaesst, dass ihn
     * schon der Richtige ruft, laesst bei einem zweiten Aufrufer alles durch.
     */
    protected function isTriggerEvent(object|array $event, string $expected): bool
    {
        return $this->get($event, 'triggerEvent') === $expected;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function matchesEventType(object|array $event, array $config): bool
    {
        $payload = $this->payloadOf($event);
        $slug = $config['event_type_slug'] ?? null;
        $id = $config['event_type_id'] ?? null;

        if (is_string($slug) && $slug !== '' && ($payload['type'] ?? null) !== $slug) {
            return false;
        }

        // Ueber die Zeichenkette verglichen, weil das Feld im Editor ein
        // Textfeld ist: dort steht "123", in der Nutzlast steht 123.
        if (is_scalar($id) && (string) $id !== '' && (string) ($payload['eventTypeId'] ?? '') !== (string) $id) {
            return false;
        }

        return true;
    }

    // --- innere Teile -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function payloadOf(object|array $event): array
    {
        $payload = $this->get($event, 'payload');

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $answers
     * @return array<int, array<string, mixed>>
     */
    protected function attendeesOf(array $payload, array $answers): array
    {
        $attendees = $payload['attendees'] ?? [];

        if (! is_array($attendees)) {
            return [];
        }

        $people = array_values(array_map(
            fn ($attendee) => $this->personOf(is_array($attendee) ? $attendee : []),
            $attendees,
        ));

        // Die Telefonnummer des Buchers steht bei cal.com nicht immer am
        // Teilnehmer, sondern in der Antwort auf das Formularfeld: bei
        // angelegten und verlegten Buchungen fehlt `phoneNumber` in
        // `attendees[]` ganz. Ohne diesen Rueckgriff waere `attendee.phone` ein
        // Feld, das der Editor anbietet und das auf keinem der fuenf Ereignisse
        // je etwas traegt.
        if (isset($people[0]) && $people[0]['phone'] === null) {
            $people[0]['phone'] = $answers['attendeePhoneNumber'] ?? null;
        }

        return $people;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function organizerOf(array $payload): array
    {
        $organizer = $payload['organizer'] ?? null;

        return $this->personOf(is_array($organizer) ? $organizer : []);
    }

    /**
     * Eine Person, immer mit denselben Schluesseln.
     *
     * Immer alle, auch wenn cal.com sie nicht mitgeschickt hat: ein Feld, das
     * mal da ist und mal fehlt, zwingt jeden Folgeknoten zu einer Fallabfrage,
     * und eine Mail-Vorlage, die auf ein fehlendes Feld trifft, bricht.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    protected function personOf(array $person): array
    {
        return [
            'name' => $this->str($person['name'] ?? null),

            // "Hallo Nina" ist die haeufigste Anrede in einer
            // Bestaetigungsmail. cal.com schickt `firstName` nur bei zwei der
            // fuenf Ereignisse mit, teilt es dort aber selbst am ersten
            // Leerzeichen aus `name` ab. Genau diese Regel steht hier als
            // Rueckfall, damit das Feld auf allen fuenf traegt statt auf zwei.
            'first_name' => $this->str($person['firstName'] ?? null)
                ?? $this->firstWordOf($person['name'] ?? null),

            'email' => $this->str($person['email'] ?? null),
            'timezone' => $this->str($person['timeZone'] ?? null),

            // `language` ist bei cal.com `{"locale": "de"}`. Hier steht die
            // Zeichenkette, weil ein Objekt in einer Mail nichts zu suchen hat.
            'language' => $this->str($person['language']['locale'] ?? null),

            'username' => $this->str($person['username'] ?? null),
            'phone' => $this->str($person['phoneNumber'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPerson(): array
    {
        return $this->personOf([]);
    }

    /**
     * Die Antworten aus dem Buchungsformular, als druckbare Zeichenketten.
     *
     * cal.com verpackt jede Antwort als `{label, value, isHidden}`, und `value`
     * ist mal ein Text, mal eine Liste, mal ein Objekt, mal gar nicht da. Was
     * hier ankommt, ist eine flache Karte "Feldname => Text". Was sich nicht in
     * Text verwandeln laesst, faellt weg: ein Feld, das mal Text und mal
     * Struktur ist, ist in einer Vorlage nicht benutzbar.
     *
     * `userFieldsResponses` fuehrt cal.com getrennt, meint aber dasselbe. Die
     * beiden werden zusammengelegt, weil die Trennung fuer einen Ablauf keine
     * ist.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function answersOf(array $payload): array
    {
        $sources = [$payload['responses'] ?? null, $payload['userFieldsResponses'] ?? null];
        $answers = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $handle => $answer) {
                $text = $this->printable(is_array($answer) && array_key_exists('value', $answer) ? $answer['value'] : $answer);

                if ($text !== null) {
                    $answers[(string) $handle] = $text;
                }
            }
        }

        return $answers;
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
            // Eine Liste von Texten wird eine Zeile. Alles Verschachtelte faellt
            // weg, statt als "Array" in einer Mail zu landen.
            $parts = array_filter(array_map(
                fn ($item) => is_scalar($item) ? (string) $item : null,
                $value,
            ), fn ($item) => is_string($item) && $item !== '');

            return $parts === [] ? null : implode(', ', $parts);
        }

        return null;
    }

    /**
     * Alle Teilnehmer-Adressen als eine Zeile.
     *
     * Fuer das `to`-Feld der Mail-Aktion, das ein einzelnes Textfeld ist. Ohne
     * das waere "schick es auch den Gaesten" nur mit einer Schleife zu bauen.
     *
     * @param  array<int, array<string, mixed>>  $people
     */
    protected function emailLineOf(array $people): ?string
    {
        $emails = array_values(array_filter(array_map(
            fn ($person) => $person['email'] ?? null,
            $people,
        )));

        return $emails === [] ? null : implode(', ', $emails);
    }

    /**
     * Die Adresse des Videoraums.
     *
     * Zwei Quellen, weil cal.com sie je nach Ereignis an verschiedenen Stellen
     * fuehrt: `metadata.videoCallUrl` ist die dokumentierte, `videoCallData.url`
     * steht bei angelegten und verlegten Buchungen daneben. Bei einer Absage
     * fehlt `metadata` ganz.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function meetingUrlOf(array $payload): ?string
    {
        return $this->str($payload['metadata']['videoCallUrl'] ?? null)
            ?? $this->str($payload['videoCallData']['url'] ?? null);
    }

    protected function get(object|array $event, string $key): mixed
    {
        return is_array($event) ? ($event[$key] ?? null) : ($event->{$key} ?? null);
    }

    /**
     * Eine Zeichenkette oder null, nie ein Array, nie eine Zahl.
     *
     * cal.com fuellt einige dieser Felder je nach Ereignis mit `null`, mit ""
     * oder mit einem Objekt. Ein Feld, das mal Text und mal Struktur ist, ist
     * in einer Mail-Vorlage nicht benutzbar.
     */
    protected function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Eine ganze Zahl oder null.
     *
     * Dieselbe Grenze wie bei `str()`, und aus demselben Grund: der
     * Token-Aufloeser macht aus einem Array, das in eine Vorlage geraet, JSON.
     * `{{ booking.id }}` schriebe dann `{"x":1}` in die Mail.
     */
    protected function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-')))
            ? (int) $value
            : null;
    }

    /**
     * Ein Zeitpunkt in einer Form, egal in welcher cal.com ihn geschickt hat.
     *
     * Dasselbe `DATE_ATOM`, das auch die Nachbar-Auslöser liefern. Was sich
     * nicht lesen laesst, geht unveraendert durch: ein unlesbarer Zeitpunkt ist
     * immer noch besser als gar keiner.
     */
    protected function date(mixed $value): ?string
    {
        $value = $this->str($value);

        if ($value === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DATE_ATOM);
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Das erste Wort eines Namens, cal.coms eigene Regel fuer `firstName`.
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
}
