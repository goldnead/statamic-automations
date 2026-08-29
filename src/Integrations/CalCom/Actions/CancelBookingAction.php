<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComClient;
use Goldnead\StatamicAutomations\Integrations\CalCom\Triggers\BookingCreatedTrigger;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Sagt einen Termin in cal.com ab.
 *
 * Der Gegenlauf zu {@see BookingCreatedTrigger}:
 * ein Ablauf hat gemerkt, dass etwas nicht stimmt (die Zahlung ist geplatzt,
 * der Kunde hat storniert, der Termin liegt in einer Sperrzeit), und nimmt den
 * Termin zurueck, statt dass jemand ihn von Hand sucht.
 *
 * ## Eine Absage ist nicht umkehrbar
 *
 * Sie schickt Mails an alle Beteiligten und entfernt den Termin aus fremden
 * Kalendern. Aus dem Addon heraus laesst sich das nicht zuruecknehmen: cal.com
 * hat kein "doch wieder anlegen", und der neue Termin waere ein anderer, mit
 * anderer Kennung, anderem Videolink und einer zweiten Mail. Deshalb schickt
 * ein Testlauf nichts, solange
 * `automations.test_mode.persist_cal_com_changes` nicht ausdruecklich an ist.
 *
 * ## Was ein doppelter Lauf anrichtet
 *
 * Nichts, aber cal.com sagt das unfreundlich. Die API kennt fuer diesen
 * Endpunkt keinen Idempotenz-Schluessel; der zweite Aufruf auf denselben
 * Termin antwortet 400 `BadRequestException` mit "because it has been
 * cancelled already" (geprueft am 29.08.2026 gegen einen echten Termin). Es
 * wird also nichts doppelt abgesagt, der Termin ist ja schon weg, und es geht
 * auch keine zweite Mail hinaus.
 *
 * Wuerde diese Aktion das durchreichen, ginge der Knoten beim zweiten Lauf rot
 * fuer genau das Ergebnis, das er herstellen sollte. Jemand suchte dann nach
 * einer fehlgeschlagenen Absage, die stattgefunden hat. Sie tut es deshalb
 * nicht, sondern **sieht nach**: nach einer Ablehnung liest sie den Termin und
 * prueft seinen Zustand. Steht er auf `cancelled` **und** hat cal.com selbst
 * mit einem 400 geurteilt, dass schon abgesagt war, ist das Ziel erreicht und
 * der Knoten geht gruen mit `{{ node.cancelled }}` gleich `false` und
 * `{{ node.already_cancelled }}` gleich `true`.
 *
 * ## Der Fall, in dem der Knoten absichtlich rot geht, obwohl abgesagt ist
 *
 * Die Absage geht hinaus, cal.com fuehrt sie aus, und die Antwort kommt nicht
 * zurueck: Zeitueberschreitung, 502, abgerissene Verbindung. Der Termin ist
 * danach abgesagt, und von hier aus ist **nicht** zu erkennen, ob dieser Lauf
 * es war oder ein frueherer.
 *
 * Diese Aktion meldet dann rot. Das ist die unbequemere und die richtige
 * Antwort. `already_cancelled` zu behaupten hiesse, die Absage einem anderen
 * Lauf zuzuschreiben, den es vielleicht nie gab: `{{ node.cancelled }}` bliebe
 * `false`, die Absage-Mail ginge in **keinem** Lauf hinaus, der Knoten waere
 * gruen, und niemand suchte danach. Der Kunde erfuehre nie, dass sein Termin
 * weg ist. Doppelt zu benachrichtigen ist aergerlich, gar nicht zu
 * benachrichtigen ist der Schaden.
 *
 * Eine Einschraenkung, die dazugehoert: die Ablauf-Maschine wiederholt einen
 * roten Knoten von sich aus. Der zweite Versuch bekommt dann den 400 und meldet
 * `already_cancelled` — richtig gegenueber cal.com, irrefuehrend gegenueber dem
 * Lauf, denn "frueher" war der eigene erste Versuch. Wer die
 * Absage-Benachrichtigung nicht verlieren will, setzt an diesem Knoten
 * `_retry_attempts` auf 0 und laesst den roten Knoten stehen.
 *
 * Das ist ausdruecklich **kein** Abfangen der Fehlermeldung an einem
 * Stichwort. Geprueft wird der Zustand drueben, nicht der Wortlaut, den
 * cal.com morgen umschreiben kann. Und es ist keine Vorabpruefung: erst
 * absagen, dann bei Ablehnung nachsehen. Andersherum waere es das Muster
 * "lesen, dann handeln", und zwischen Lesen und Handeln kann ein zweiter Lauf
 * dazwischenkommen.
 *
 * `{{ node.cancelled }}` ist damit die Auskunft, auf die ein Ablauf verzweigt,
 * wenn danach noch etwas passieren soll: `true` heisst "dieser Lauf hat es
 * getan", `false` heisst "es war schon so". Eine Benachrichtigung hinter
 * diesem Knoten geht sonst beim zweiten Lauf ein zweites Mal hinaus, und das
 * ist der Schaden, den die 400 gerade nicht anrichtet.
 */
class CancelBookingAction implements AutomationAction
{
    public function __construct(protected CalComClient $client) {}

    public static function handle(): string
    {
        return 'cal_com.cancel_booking';
    }

    public static function label(): string
    {
        return 'Cancel Booking (cal.com)';
    }

    public static function description(): ?string
    {
        return 'Cancels a cal.com booking, with a reason everybody involved gets to see.';
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
                'handle' => 'booking_uid',
                'label' => 'Booking',
                'type' => 'data_reference',
                'source' => 'booking',
                'required' => true,
                'help' => 'The booking to cancel, usually {{ booking.uid }} from a cal.com trigger. This is the uid, the long letter string, not the numeric id.',
            ],
            [
                'handle' => 'reason',
                'label' => 'Reason',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Why the booking is being cancelled. cal.com puts this in the cancellation mail everybody involved receives, so write it for them and not for the log.',
            ],
        ];
    }

    /**
     * Was dieser Knoten nach unten weitergibt.
     *
     * `cancelled` und `already_cancelled` sind zwei verschiedene Auskuenfte und
     * nicht das Gegenteil voneinander: `cancelled` heisst "dieser Lauf hat es
     * getan", `already_cancelled` heisst "es war beim Eintreffen schon so".
     * Ein Ablauf, der danach eine Mail schickt, haengt sie an `cancelled`, sonst
     * geht sie beim zweiten Lauf ein zweites Mal hinaus.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'uid' => 'string',
            'status' => 'string',
            'cancelled' => 'boolean',
            'already_cancelled' => 'boolean',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $uid = $config['booking_uid'] ?? $context->get('booking.uid');
        $reason = $config['reason'] ?? null;

        // Statische Konfiguration, vor dem Testmodus-Zweig: ein Knoten ohne
        // Grund ist falsch eingerichtet, und ein Testlauf hat das zu sagen.
        // Der Grund ist Pflicht, weil er im Absage-Schreiben steht, das der
        // Teilnehmer bekommt. Eine Absage ohne Begruendung ist die, wegen der
        // er anruft.
        if (! is_string($reason) || trim($reason) === '') {
            return ActionResult::failed('A reason is required to cancel a booking.');
        }

        $reason = trim($reason);

        // Die Datenreferenz wird absichtlich erst nach diesem Zweig geprueft:
        // siehe ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_cal_com_changes', false)) {
            return ActionResult::success([
                'preview' => ['booking_uid' => $uid, 'reason' => $reason],
                'note' => 'Test mode — nothing was cancelled in cal.com.',
            ]);
        }

        if (! is_string($uid) || trim($uid) === '') {
            return ActionResult::missingDataReference('booking_uid', 'Booking', '{{ booking.uid }}');
        }

        $uid = trim($uid);

        if (! $this->client->isConfigured()) {
            return ActionResult::failed('cal.com is not configured: set the API key before using this action.');
        }

        $result = $this->client->cancelBooking($uid, $reason);

        // Angenommen **und** belegt: cal.com gibt den Termin zurueck und der
        // steht auf `cancelled`. Nur dann ist der Fall erledigt.
        if ($result->ok && ($result->data['status'] ?? null) === 'cancelled') {
            return ActionResult::success([
                'uid' => $result->data['uid'] ?? $uid,
                'status' => 'cancelled',
                'cancelled' => true,
                'already_cancelled' => false,
            ]);
        }

        // Alles andere ist unklar, und Unklarheit wird hier nachgesehen statt
        // ausgelegt. Zwei Wege fuehren hierher:
        //
        //   - Der zweite Lauf desselben Ablaufs. cal.com lehnt mit 400 ab,
        //     wenn der Termin schon abgesagt ist. 400 heisst aber nicht nur
        //     das, und der Wortlaut der Meldung ist kein Beleg.
        //   - Eine angenommene Anfrage, deren Antwort den Zustand nicht
        //     hergibt. So sieht es zum Beispiel aus, wenn eine Antwort in einer
        //     anderen Form kommt als erwartet — und weil cal.com bei falscher
        //     `cal-api-version` teils mit 200 und anderer Form antwortet, ist
        //     das kein erfundener Fall. Hier gruen zu melden hiesse, eine
        //     Absage zu behaupten, die niemand belegt hat, und jemand faende
        //     sich in einem Termin wieder, den er abgesagt glaubte.
        //
        // Beide Wege enden bei derselben Frage: wie ist der Zustand drueben
        // wirklich? Und die wird gestellt, nachdem abgesagt wurde, nicht davor.
        // Andersherum waere es das Muster "lesen, dann handeln", und zwischen
        // Lesen und Handeln kann ein zweiter Lauf dazwischenkommen.
        $existing = $this->client->booking($uid);
        $isCancelled = $existing->ok && ($existing->data['status'] ?? null) === 'cancelled';

        // Der Termin ist weg. Bleibt die Frage, die ueber die
        // Folge-Benachrichtigung entscheidet: **wer** hat ihn weggeraeumt?
        //
        // Belegt ist das nur in einem Fall: cal.com hat die Absage mit einem
        // eigenen 400 abgelehnt und dabei selbst beurteilt, dass schon
        // abgesagt war. Dann war es ein frueherer Lauf.
        if ($isCancelled && $result->status === 400 && $result->recognised) {
            return ActionResult::success([
                'uid' => $existing->data['uid'] ?? $uid,
                'status' => 'cancelled',
                'cancelled' => false,
                'already_cancelled' => true,
            ]);
        }

        // Und der Fall, den man beim Bauen vergisst und im Betrieb bezahlt:
        // die Absage ging hinaus, cal.com hat sie ausgefuehrt, und die Antwort
        // kam nicht zurueck (Zeitueberschreitung, 502, abgerissene
        // Verbindung). Der Termin steht danach auf `cancelled`, und von hier
        // aus ist **nicht** zu erkennen, ob dieser Lauf ihn abgesagt hat oder
        // ein frueherer.
        //
        // Hier `already_cancelled` zu melden waere bequem und die schlimmere
        // Haelfte des Fehlers, gegen den dieser Knoten ueberhaupt geschrieben
        // ist: gruen, ohne Alarm, und `{{ node.cancelled }}` bleibt `false`.
        // Die Absage-Mail ginge dann nicht doppelt hinaus, sondern **gar
        // nicht**, in keinem Lauf, und niemand sucht danach. Der Kunde
        // erfaehrt nie, dass sein Termin weg ist.
        //
        // Rot ist deshalb hier die richtige Antwort. Ein Mensch klaert das in
        // einer halben Minute; ein stiller gruener Knoten nie.
        if ($isCancelled) {
            return ActionResult::failed(
                "The cal.com booking {$uid} is cancelled, but it is not established that this run did it: "
                    .($result->error ?? 'the cancellation got no usable answer.')
                    .' The follow-up is deliberately not claimed either way, because a cancellation mail that '
                    .'never goes out is worse than one that goes out twice.',
                [
                    'uid' => $uid,
                    'status' => 'cancelled',
                    'cancelled' => false,
                    'already_cancelled' => false,
                ],
            );
        }

        return ActionResult::failed(
            $result->ok
                ? 'cal.com accepted the cancellation but does not report the booking as cancelled. '
                    .'Nothing here proves it went through, so this node does not claim it did.'
                : ($result->error ?? 'Cancelling the cal.com booking failed.'),
            [
                'uid' => $uid,
                // Wie weit es kam, bevor es abbrach. Wer aufraeumt, braucht das
                // zuerst, und ein blosses "failed" traegt es nicht. Beide
                // Felder, nicht nur eines: ein Zweig, der sie liest, bekommt
                // sonst das andere unaufgeloest.
                'cancelled' => false,
                'already_cancelled' => false,
            ],
        );
    }
}
