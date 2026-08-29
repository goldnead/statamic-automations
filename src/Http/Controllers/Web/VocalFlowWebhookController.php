<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Web;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowDeliveries;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowEvents;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowSignature;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Die Tuer, durch die VocalFlows Ereignisse hereinkommen.
 *
 * Der Anschluss an VocalFlow bringt seine eigene Route mit und setzt kein
 * zweites Addon voraus. Die Route ist oeffentlich, ohne Sitzung und ohne
 * Anmeldung, weil sie von VocalFlows Servern aufgerufen wird. Was an ihre
 * Stelle tritt, steht in dieser Reihenfolge und nicht in einer anderen:
 *
 *   1. Ohne konfiguriertes Secret nimmt sie nichts an. Nicht "unsigniert
 *      durchlassen, bis jemand ein Secret eintraegt": eine Route, die ohne
 *      Zugangsdaten alles annimmt, ist ein Formular, in das jeder Fremde
 *      Ereignisse schreiben kann, die dann Mails ausloesen.
 *   2. Groessenschranke, bevor irgendetwas gerechnet wird.
 *   3. `json_decode` mit Tiefenbegrenzung.
 *   4. Signaturpruefung ueber die kanonische Fassung. Alles nach diesem Punkt
 *      darf als "kommt von VocalFlow" behandelt werden, alles davor nicht.
 *   5. Altersgrenze gegen das Wiedereinspielen eines mitgeschnittenen Rumpfes.
 *   6. Schutz gegen Doppelzustellung, vor dem Start des Ablaufs.
 *
 * **Punkt 3 vor Punkt 4 ist der Unterschied zu cal.com**, und der einzige. Dort
 * liegt die Signaturpruefung vor jedem Dekodieren, weil cal.com die rohen Bytes
 * signiert. VocalFlow signiert eine kanonisch neu kodierte Fassung
 * ({@see VocalFlowSignature} begruendet das ausfuehrlich), das Dekodieren ist
 * hier also Teil der Pruefung und laesst sich nicht dahinter schieben.
 *
 * Was dieser Tausch aufmacht, ist eng: ein Fremder ohne Secret kann das
 * Dekodieren einer Zeichenkette ausloesen, deren Laenge durch Schritt 2
 * gedeckelt und deren Verschachtelung durch `VocalFlowSignature::MAX_DEPTH`
 * begrenzt ist. Er erreicht damit nichts, was Zustand aendert: der erste
 * Schritt, der etwas anfasst, ist Schritt 6, und dorthin kommt nur, wer
 * signiert hat.
 *
 * Die Antwort-Codes sind fuer VocalFlows Zustellprotokoll gewaehlt: 2xx heisst
 * "angekommen, nicht noch einmal schicken", 4xx heisst "die Anfrage taugt
 * nicht, ein zweiter Versuch aendert daran nichts", 5xx heisst "hier ging
 * gerade etwas schief, schick es noch einmal".
 *
 * Dass 503 (kein Secret) und 403 (falsche Signatur) sich unterscheiden lassen,
 * ist Absicht und in Kauf genommen: wer die URL kennt, erfaehrt damit, ob hier
 * ein Secret hinterlegt ist. Der Gegenwert ist, dass genau diese Auskunft im
 * Zustellprotokoll steht, wo derjenige sie sucht, der den Anschluss einrichtet.
 * Eine falsche Fehlermeldung an der Stelle kostet Stunden.
 */
class VocalFlowWebhookController
{
    public function __construct(
        protected TriggerDispatcher $dispatcher,
        protected TriggerRegistry $triggers,
        protected VocalFlowDeliveries $deliveries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('automations.integrations.vocalflow.secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['status' => 'not_configured'], 503);
        }

        $raw = $request->getContent();

        // Vor dem Dekodieren und vor dem HMAC: beide laufen ueber den ganzen
        // Rumpf, und der Rumpf liegt als ein Stueck im Speicher. Eine
        // VocalFlow-Nutzlast ist wenige Kilobyte gross.
        if (strlen($raw) > $this->maxBodyBytes()) {
            return response()->json(['status' => 'too_large'], 413);
        }

        // Zweimal dekodiert, und das ist Absicht.
        //
        // `$structure` ist der Objekt-Graph und geht in die Signatur: nur dort
        // bleibt ein leeres JSON-Objekt ein Objekt und wird nicht zu `[]`, was
        // die kanonische Fassung veraendern und die Signatur zerreissen wuerde
        // (siehe VocalFlowSignature::canonical).
        //
        // `$envelope` ist dasselbe als assoziatives Array und geht in die
        // Verarbeitung, weil der Rest dieses Anschlusses mit Arrays arbeitet.
        //
        // Der zweite Durchgang kostet das Dekodieren einer Zeichenkette, deren
        // Laenge oben schon gedeckelt ist. Die Alternative waere, den Graphen
        // umzuwandeln, und das ist derselbe Aufwand mit mehr Code.
        $structure = json_decode($raw, false, VocalFlowSignature::MAX_DEPTH);
        $envelope = json_decode($raw, true, VocalFlowSignature::MAX_DEPTH);

        if (! is_array($envelope)) {
            // Der haeufigste Grund dafuer ist keine kaputte Anfrage, sondern
            // ein falsch eingetragener Empfaenger: dann kommt der Rumpf als
            // `application/x-www-form-urlencoded` und ist kein JSON mehr. Das
            // ist eine Fehlkonfiguration, die sonst niemandem auffiele, weil
            // 4xx bei VocalFlow nur still im Protokoll steht.
            Log::warning('statamic-automations: VocalFlow hat etwas geschickt, das kein JSON-Objekt ist.', [
                'content_type' => $request->header('content-type'),
                'bytes' => strlen($raw),
            ]);

            return response()->json(['status' => 'malformed'], 400);
        }

        // Genau einmal gebildet, und danach beides daraus: die Signatur und
        // unten die Kennung gegen Doppelzustellung. Ein zweiter Aufruf waere
        // ein zweites Ergebnis, das vom ersten abweichen kann, und dann pruefte
        // dieser Controller etwas anderes, als er sich merkt.
        $canonical = VocalFlowSignature::canonical($structure);

        if ($canonical === null) {
            // Laesst sich nicht kodieren, also laesst sich keine Signatur
            // bilden, also ist nicht feststellbar, ob es von VocalFlow kommt.
            // Dieselbe Antwort wie bei einer falschen Signatur: die Anfrage ist
            // nicht als echt nachweisbar, und mehr sagt 403 auch nicht.
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        if (! VocalFlowSignature::matchesCanonical($secret, $canonical, $request->header(VocalFlowSignature::HEADER))) {
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        // Ab hier steht fest, dass der Inhalt von VocalFlow stammt.

        $event = $envelope['event'] ?? null;

        if (! is_string($event) || $event === '') {
            return response()->json(['status' => 'no_event'], 400);
        }

        $handle = VocalFlowEvents::handleFor($event);

        // Ein Ereignis, fuer das dieses Addon keinen Auslöser hat, oder einer,
        // den jemand ueber `automations.builtin_nodes` abgeschaltet hat. 202
        // statt 200: die Anfrage war in Ordnung, sie hat hier nur nichts zu
        // tun. "ok" zu antworten waere Erfolg zu melden fuer nichts getan.
        if ($handle === null || ! $this->triggers->has($handle)) {
            return response()->json(['status' => 'ignored', 'event' => $event], 202);
        }

        // VocalFlow legt zwar ein `X-Webhook-Timestamp` in die Kopfzeilen, das
        // ist aber **nicht mitsigniert** und damit als Schranke wertlos: wer
        // einen mitgeschnittenen Rumpf hat, setzt den Header frisch. Der
        // `timestamp` im Rumpf ist mitsigniert und schliesst das.
        if ($this->isStale($envelope)) {
            return response()->json(['status' => 'stale'], 400);
        }

        // Die Kennung ist der Fingerabdruck der signierten Nutzlast, nicht die
        // Kennung des Vorgangs. Warum, steht in VocalFlowDeliveries: eine
        // Aufgabe wird mehrfach echt geaendert, eine Buchung nicht.
        $identity = hash('sha256', $canonical);

        if (! $this->deliveries->firstSeen($event, $identity)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        try {
            $this->dispatcher->dispatch($handle, $envelope);
        } catch (Throwable $e) {
            // Die Vormerkung zuruecknehmen, sonst ist das Ereignis verloren: es
            // stuende im Cache als erledigt, VocalFlows Wiederholung liefe in
            // "schon dagewesen", und der Ablauf startete nie. Ein 500 hier ist
            // das, was VocalFlow dazu bringt, es noch einmal zu versuchen.
            $this->deliveries->release($event, $identity);

            throw $e;
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Ist der Umschlag aelter als erlaubt?
     *
     * Fehlt `timestamp` oder laesst es sich nicht lesen, gilt der Umschlag als
     * frisch. Fail-closed waere hier die schlechtere Wahl: ein Anschluss, der
     * ein echtes Ereignis wegen eines fehlenden Nebenfeldes verwirft, ist
     * kaputter als einer, der einen Mitschnitt annimmt. Die
     * Doppelzustellungs-Schranke faengt den unmittelbaren Fall ohnehin ab.
     *
     * **Aber nicht stumm.** Diese Schranke ist die einzige, die ueber das
     * Dedupe-Fenster hinaus vor einem wiedereingespielten Mitschnitt schuetzt.
     * Faellt `timestamp` auf VocalFlows Seite eines Tages weg oder wird
     * umbenannt, verschwindet sie lautlos, und niemand sucht danach. Deshalb
     * eine Zeile ins Log, wenn das Feld auf einer echten, signierten Zustellung
     * fehlt. Heute schickt VocalFlow es immer, die Zeile ist also entweder
     * still oder eine Nachricht.
     *
     * @param  array<mixed>  $envelope
     */
    protected function isStale(array $envelope): bool
    {
        $minutes = $this->maxAgeMinutes();

        if ($minutes <= 0) {
            return false;
        }

        $timestamp = $envelope['timestamp'] ?? null;

        if (! is_string($timestamp) || $timestamp === '') {
            Log::warning('statamic-automations: VocalFlows Zustellung hat kein `timestamp`, die Altersgrenze wirkt fuer sie nicht.', [
                'event' => is_string($envelope['event'] ?? null) ? $envelope['event'] : null,
            ]);

            return false;
        }

        try {
            $moment = new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            Log::warning('statamic-automations: VocalFlows `timestamp` laesst sich nicht lesen, die Altersgrenze wirkt fuer diese Zustellung nicht.', [
                'timestamp' => $timestamp,
            ]);

            return false;
        }

        return $moment->getTimestamp() < now()->subMinutes($minutes)->getTimestamp();
    }

    protected function maxAgeMinutes(): int
    {
        $configured = config('automations.integrations.vocalflow.max_age_minutes', 1440);

        return is_numeric($configured) ? (int) $configured : 1440;
    }

    protected function maxBodyBytes(): int
    {
        $configured = config('automations.integrations.vocalflow.max_body_bytes', 262144);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 262144;
    }
}
