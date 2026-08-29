<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComClient;
use Goldnead\StatamicAutomations\Integrations\CalCom\Triggers\BookingRequestedTrigger;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Legt einen Termin in cal.com an.
 *
 * Der Schluss des Kreises: {@see GetSlotsAction} holt die freien Zeiten, eine
 * Mail oder ein Formular macht daraus eine Auswahl, und dieser Knoten setzt
 * den Termin. Damit laeuft eine Wiedervorlage ohne Handgriff durch.
 *
 * ## Warum ein neuer Termin manchmal `pending` ist, und was das heisst
 *
 * Der haeufigste Stolperstein an diesem Knoten, und er sieht aus wie ein
 * Fehler des Addons, ist keiner.
 *
 * cal.com antwortet auf eine Anlage mit `status` `accepted` **oder**
 * `pending`, und was von beidem, haengt allein an der Terminart: an ihrer
 * `confirmationPolicy`. Steht die auf `always` (oder auf `time` innerhalb der
 * Frist), ist der Termin eine **Anfrage**, die erst bestaetigt werden muss;
 * steht sie auf `disabled`, steht der Termin sofort. Gemessen am 29.08.2026
 * gegen zwei echte Terminarten desselben Kontos: eine mit `always` antwortete
 * `pending`, eine mit `disabled` antwortete `accepted`.
 *
 * Der Punkt, der Zeit kostet: `GET /v2/event-types` gibt dieses Feld **nicht**
 * heraus, und es heisst dort auch nicht `requiresConfirmation`. Wer in jener
 * Liste nachsieht, findet das Feld nicht und liest die Abwesenheit als "keine
 * Bestaetigung noetig". Der Wert steht nur in `GET /v2/event-types/{id}`, und
 * er heisst `confirmationPolicy`.
 *
 * Daran haengt das Zweite, was verwirrt: **`BOOKING_CREATED` feuert bei einem
 * `pending`-Termin nicht.** Das ist ebenfalls richtig so. cal.com schickt fuer
 * eine Buchung, die auf Bestaetigung wartet, `BOOKING_REQUESTED`, und dafuer
 * gibt es in diesem Addon einen eigenen Auslöser
 * ({@see BookingRequestedTrigger}).
 * `BOOKING_CREATED` kommt erst, wenn jemand bestaetigt.
 *
 * Ein Ablauf, der hinter diesem Knoten auf einen stehenden Termin baut, haengt
 * sich deshalb an `{{ node.confirmed }}` und nicht daran, dass der Knoten
 * gruen ist. Gruen heisst "cal.com hat es angenommen", nicht "der Termin
 * steht". Diese Aktion gibt darum den Zustand zurueck und nicht ein
 * "erledigt".
 *
 * ## Was ein doppelter Lauf anrichtet
 *
 * cal.com kennt fuer diesen Endpunkt keinen Idempotenz-Schluessel. Was den
 * zweiten Lauf trotzdem abfaengt, ist der Kalender: derselbe Zeitpunkt ein
 * zweites Mal ergibt 409 `ConflictException` und **keinen** zweiten Termin
 * (geprueft am 29.08.2026 mit zwei echten Anlagen auf denselben Slot). Der
 * Knoten geht dann rot.
 *
 * Was daraus **nicht** folgt: dass ein Termin steht. cal.coms Meldung lautet
 * "User either already has booking at this time **or is not available**", und
 * die drei Faelle dahinter sind von hier aus nicht zu trennen: der zweite Lauf
 * desselben Ablaufs, jemand anderes war schneller, oder der Zeitpunkt liegt
 * schlicht ausserhalb der Verfuegbarkeit (eine falsche Zeitzone reicht). In
 * den letzten beiden Faellen gab es nie einen ersten Lauf und es steht kein
 * Termin. Das Ausgabefeld heisst deshalb `slot_unavailable` und nicht
 * `conflict`: es sagt, was belegt ist. Ein Aufraeum-Zweig, der daraus auf
 * einen bestehenden Termin schliesst, sagt einen ab, den es nicht gibt, oder
 * unterdrueckt die Meldung an einen Menschen, waehrend der Kunde ohne Termin
 * dasteht.
 *
 * Der Schutz kommt also vom Kalender und nicht von der API, und daraus folgt
 * die eine Bauregel fuer diesen Knoten: **der Zeitpunkt muss von aussen
 * kommen, nicht aus einer Rechnung im Ablauf.** Wer hier
 * `{{ slots.first }}` aus einem Knoten einsetzt, der bei jedem Lauf neu
 * fragt, bekommt beim zweiten Lauf einen anderen Zeitpunkt, keinen Konflikt
 * und einen zweiten Termin. Was der Kunde gewaehlt hat, gehoert deshalb in
 * den Kontext des Laufs, nicht in eine erneute Abfrage.
 *
 * ## Ein Testlauf legt nichts an
 *
 * Ein Termin schickt Bestaetigungen, landet in fremden Kalendern und laesst
 * sich von hier aus nur durch eine Absage zuruecknehmen, die eine zweite Mail
 * ausloest. Deshalb schickt ein Testlauf nichts, solange
 * `automations.test_mode.persist_cal_com_changes` nicht ausdruecklich an ist.
 */
class CreateBookingAction implements AutomationAction
{
    public function __construct(protected CalComClient $client) {}

    public static function handle(): string
    {
        return 'cal_com.create_booking';
    }

    public static function label(): string
    {
        return 'Create Booking (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Books a slot in cal.com and reports whether it stands or is waiting for confirmation.';
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
                'help' => 'The numeric ID of the cal.com event type to book. Whether the booking stands right away or waits for confirmation is decided here and nowhere else, by the event type\'s confirmation policy.',
            ],
            [
                'handle' => 'start',
                'label' => 'Start',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'ISO 8601 timestamp of the start, for example 2026-09-01T13:00:00.000Z. Take it from what the person picked, not from a fresh lookup: a re-run that looks up again picks a different time and books a second appointment.',
            ],
            [
                'handle' => 'attendee_name',
                'label' => 'Name',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Who is being booked in, for example {{ booking.attendee.name }} or {{ contact.name }}.',
            ],
            [
                'handle' => 'attendee_email',
                'label' => 'Email',
                'type' => 'data_reference',
                'source' => 'contact',
                'required' => true,
                'help' => 'The address the confirmation goes to, usually {{ booking.attendee.email }} or {{ contact.email }}.',
            ],
            [
                'handle' => 'attendee_time_zone',
                'label' => 'Time zone',
                'type' => 'text',
                'required' => true,
                'default' => 'UTC',
                'tokenable' => true,
                'help' => 'The attendee\'s time zone, for example Europe/Berlin. cal.com writes every time in the confirmation in this zone, so a wrong one sends somebody to the right appointment at the wrong hour.',
            ],
            [
                'handle' => 'attendee_language',
                'label' => 'Language',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional two-letter code, for example de. Picks the language of cal.com\'s confirmation mail.',
            ],
        ];
    }

    /**
     * Was dieser Knoten nach unten weitergibt.
     *
     * `status` ist cal.coms Wort (`accepted` oder `pending`), `confirmed` die
     * Ja-Nein-Fassung davon, an die ein Ablauf sich haengt. Beide stehen hier,
     * weil `status` in eine Mail gehoert und `confirmed` in eine Verzweigung.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'uid' => 'string',
            'id' => 'integer',
            'status' => 'string',
            'confirmed' => 'boolean',
            'start' => 'string',
            'end' => 'string',
            'meeting_url' => 'string',
            'event_type_id' => 'integer',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $eventTypeId = $config['event_type_id'] ?? null;
        $start = $config['start'] ?? null;
        $name = $config['attendee_name'] ?? null;
        $timeZone = $config['attendee_time_zone'] ?? null;
        $email = $config['attendee_email'] ?? $context->get('contact.email');

        // Statische Konfiguration, vor dem Testmodus-Zweig. Alles hier ist im
        // Knoten eingestellt und nicht aus dem Lauf gefischt; ein Testlauf ist
        // dafuer da, einen falsch eingerichteten Knoten zu zeigen.
        if (! is_numeric($eventTypeId)) {
            return ActionResult::failed('A numeric event type ID is required.');
        }

        if (! is_string($start) || trim($start) === '') {
            return ActionResult::failed('A start is required.');
        }

        if (! is_string($name) || trim($name) === '') {
            return ActionResult::failed('A name is required.');
        }

        // Keine Vorgabe auf die Zeitzone der Seite: cal.com setzt jede Zeit im
        // Bestaetigungsschreiben in diese Zone um, und eine stillschweigend
        // angenommene schickt jemanden zum richtigen Termin zur falschen
        // Stunde. Das Feld hat eine Vorgabe im Schema, aber wer sie leert,
        // bekommt einen roten Knoten und keine Annahme.
        if (! is_string($timeZone) || trim($timeZone) === '') {
            return ActionResult::failed('A time zone is required: cal.com writes every time in the confirmation in it.');
        }

        $payload = [
            'eventTypeId' => (int) $eventTypeId,
            'start' => trim($start),
            'attendee' => [
                'name' => trim($name),
                'timeZone' => trim($timeZone),
            ],
        ];

        $language = $config['attendee_language'] ?? null;

        if (is_string($language) && trim($language) !== '') {
            $payload['attendee']['language'] = trim($language);
        }

        // Die Datenreferenz wird absichtlich erst nach diesem Zweig geprueft:
        // siehe ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_cal_com_changes', false)) {
            return ActionResult::success([
                'preview' => ['attendee_email' => $email] + $payload,
                'note' => 'Test mode — nothing was booked in cal.com.',
            ]);
        }

        if (! is_string($email) || trim($email) === '') {
            return ActionResult::missingDataReference('attendee_email', 'Email', '{{ contact.email }}');
        }

        $payload['attendee']['email'] = strtolower(trim($email));

        if (! $this->client->isConfigured()) {
            return ActionResult::failed('cal.com is not configured: set the API key before using this action.');
        }

        $result = $this->client->createBooking($payload);

        if (! $result->ok) {
            return ActionResult::failed($result->error ?? 'Creating the cal.com booking failed.', [
                'event_type_id' => (int) $eventTypeId,
                'start' => trim($start),
                // Der Zeitpunkt war nicht zu haben. Warum nicht, sagt cal.com
                // nicht: der zweite Lauf desselben Ablaufs, jemand anderes,
                // oder gar keine Verfuegbarkeit. Das Feld heisst deshalb nach
                // dem, was belegt ist, und nicht nach der haeufigsten
                // Vermutung.
                'slot_unavailable' => $result->status === 409,
            ]);
        }

        $uid = $result->data['uid'] ?? null;

        // Ein Termin ohne Kennung ist kein Termin. cal.com gibt sie immer
        // heraus; kommt sie nicht, ist die Antwort eine andere als erwartet
        // (so sieht es unter anderem aus, wenn die `cal-api-version` nicht
        // passt und cal.com in einer aelteren Form antwortet). Gruen zu melden
        // hiesse hier, einen Termin zu behaupten und den naechsten Knoten mit
        // einem leeren `{{ node.uid }}` weiterarbeiten zu lassen — und der
        // sagt dann einen Termin ab, den er nicht findet.
        if (! is_string($uid) || $uid === '') {
            return ActionResult::failed(
                'cal.com accepted the booking but returned no uid, so there is nothing here that proves a booking exists.',
                [
                    'event_type_id' => (int) $eventTypeId,
                    'start' => trim($start),
                    // Auch hier gesetzt, nicht nur im Zweig darueber: ein
                    // Ablauf, der auf dieses Feld verzweigt, bekaeme sonst
                    // einmal `false` und einmal gar nichts.
                    'slot_unavailable' => false,
                ],
            );
        }

        $status = $result->data['status'] ?? null;
        $status = is_string($status) && $status !== '' ? $status : 'unknown';

        return ActionResult::success([
            'uid' => $uid,
            'id' => $result->data['id'] ?? null,
            // Nur `accepted` heisst, dass der Termin steht. `pending` ist eine
            // Anfrage, und alles andere ist etwas, das cal.com neu erfunden
            // hat, seit das hier geschrieben wurde — beides ist kein
            // bestaetigter Termin, und die Vorgabe muss hier die vorsichtige
            // sein.
            'status' => $status,
            'confirmed' => $status === 'accepted',
            'start' => $result->data['start'] ?? null,
            'end' => $result->data['end'] ?? null,
            'meeting_url' => $result->data['meetingUrl'] ?? null,
            'event_type_id' => $result->data['eventTypeId'] ?? (int) $eventTypeId,
        ]);
    }
}
