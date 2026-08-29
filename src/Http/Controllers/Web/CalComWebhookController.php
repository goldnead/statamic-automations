<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Web;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComDeliveries;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComEvents;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComSignature;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Die Tuer, durch die cal.com hereinkommt.
 *
 * Der Anschluss an cal.com bringt seine eigene Route mit und setzt kein
 * zweites Addon voraus. Ein Anschluss, der erst funktioniert, wenn man noch
 * etwas anderes installiert, ist ein schlechterer Anschluss.
 *
 * Die Route ist oeffentlich, ohne Sitzung und ohne Anmeldung, weil sie von
 * cal.coms Servern aufgerufen wird. Was an ihre Stelle tritt, steht in dieser
 * Reihenfolge und nicht in einer anderen:
 *
 *   1. Ohne konfiguriertes Secret nimmt sie nichts an. Nicht "unsigniert
 *      durchlassen, bis jemand ein Secret eintraegt": eine Route, die ohne
 *      Zugangsdaten alles annimmt, ist ein Formular, in das jeder Fremde
 *      Buchungen schreiben kann, die dann Mails ausloesen.
 *   2. Groessenschranke, bevor irgendetwas gerechnet wird.
 *   3. Signaturpruefung ueber den rohen Rumpf, bevor irgendetwas dekodiert
 *      wird. Alles nach diesem Punkt darf als "kommt von cal.com" behandelt
 *      werden, alles davor nicht.
 *   4. Erst danach `json_decode`.
 *   5. Altersgrenze gegen das Wiedereinspielen eines mitgeschnittenen Rumpfes.
 *   6. Schutz gegen Doppelzustellung, vor dem Start des Ablaufs.
 *
 * Die Antwort-Codes sind fuer cal.coms Zustellprotokoll gewaehlt: 2xx heisst
 * "angekommen, nicht noch einmal schicken", 4xx heisst "die Anfrage taugt
 * nicht, ein zweiter Versuch aendert daran nichts", 5xx heisst "hier ging
 * gerade etwas schief, schick es noch einmal".
 *
 * Dass 503 (kein Secret) und 403 (falsche Signatur) sich unterscheiden lassen,
 * ist Absicht und in Kauf genommen: wer die URL kennt, erfaehrt damit, ob hier
 * ein Secret hinterlegt ist. Der Gegenwert ist, dass genau diese Auskunft in
 * cal.coms Zustellprotokoll steht, wo derjenige sie sucht, der den Anschluss
 * einrichtet. Eine falsche Fehlermeldung an der Stelle kostet Stunden.
 */
class CalComWebhookController
{
    public function __construct(
        protected TriggerDispatcher $dispatcher,
        protected TriggerRegistry $triggers,
        protected CalComDeliveries $deliveries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('automations.integrations.cal_com.secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['status' => 'not_configured'], 503);
        }

        // Der rohe Rumpf. `$request->all()` waere hier schon der dekodierte
        // Inhalt und damit das falsche Material fuer eine Signatur.
        $raw = $request->getContent();

        // Vor dem HMAC, nicht danach: die Pruefsumme laeuft ueber den ganzen
        // Rumpf, und der Rumpf liegt als ein Stueck im Speicher. Eine
        // cal.com-Nutzlast ist wenige Kilobyte gross.
        if (strlen($raw) > $this->maxBodyBytes()) {
            return response()->json(['status' => 'too_large'], 413);
        }

        if (! CalComSignature::matches($secret, $raw, $request->header(CalComSignature::HEADER))) {
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        // Ab hier steht fest, dass die Bytes von cal.com stammen.
        $envelope = json_decode($raw, true);

        if (! is_array($envelope)) {
            // Der haeufigste Grund dafuer ist keine kaputte Anfrage, sondern
            // eine Nutzlast-Vorlage im cal.com-Konto: dann kommt der Rumpf als
            // `application/x-www-form-urlencoded` und ist kein JSON mehr. Das
            // ist eine Fehlkonfiguration, die sonst niemandem auffiele, weil
            // 4xx bei cal.com nur still im Protokoll steht.
            Log::warning('statamic-automations: cal.com hat etwas geschickt, das kein JSON ist. Steht im cal.com-Webhook eine Nutzlast-Vorlage?', [
                'content_type' => $request->header('content-type'),
                'bytes' => strlen($raw),
            ]);

            return response()->json(['status' => 'malformed'], 400);
        }

        $triggerEvent = $envelope['triggerEvent'] ?? null;

        if (! is_string($triggerEvent) || $triggerEvent === '') {
            return response()->json(['status' => 'no_trigger_event'], 400);
        }

        $handle = CalComEvents::handleFor($triggerEvent);

        // Ein Ereignis, fuer das dieses Addon keinen Auslöser hat, oder einer,
        // den jemand ueber `automations.builtin_nodes` abgeschaltet hat. 202
        // statt 200: die Anfrage war in Ordnung, sie hat hier nur nichts zu
        // tun. "ok" zu antworten waere Erfolg zu melden fuer nichts getan.
        if ($handle === null || ! $this->triggers->has($handle)) {
            return response()->json(['status' => 'ignored', 'trigger_event' => $triggerEvent], 202);
        }

        // cal.com legt weder eine Zustell-Kennung noch einen eigenen Zeitstempel
        // in die Kopfzeilen, aber `createdAt` steht im Rumpf und ist damit
        // mitsigniert. Ohne diese Schranke bliebe ein einmal mitgeschnittener
        // Rumpf fuer immer gueltig: wer ihn aus einem Protokoll, einem Proxy
        // oder einem WAF-Mitschnitt hat, koennte den Ablauf beliebig oft neu
        // ausloesen, sobald das Dedupe-Fenster abgelaufen ist.
        if ($this->isStale($envelope)) {
            return response()->json(['status' => 'stale'], 400);
        }

        $uid = $envelope['payload']['uid'] ?? null;
        $uid = is_string($uid) ? $uid : null;

        if (! $this->deliveries->firstSeen($triggerEvent, $uid, $raw)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        try {
            $this->dispatcher->dispatch($handle, $envelope);
        } catch (Throwable $e) {
            // Die Vormerkung zuruecknehmen, sonst ist die Buchung verloren: sie
            // stuende im Cache als erledigt, cal.coms Wiederholung liefe in
            // "schon dagewesen", und der Ablauf startete nie. Ein 500 hier ist
            // das, was cal.com dazu bringt, es noch einmal zu versuchen.
            $this->deliveries->release($triggerEvent, $uid, $raw);

            throw $e;
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Ist der Umschlag aelter als erlaubt?
     *
     * Fehlt `createdAt` oder laesst es sich nicht lesen, gilt der Umschlag als
     * frisch. Fail-closed waere hier die schlechtere Wahl: cal.coms Nutzlasten
     * unterscheiden sich je Ereignis, und ein Anschluss, der eine echte Buchung
     * wegen eines fehlenden Nebenfeldes verwirft, ist kaputter als einer, der
     * einen Mitschnitt annimmt. Die Doppelzustellungs-Schranke faengt den
     * unmittelbaren Fall ohnehin ab.
     *
     * @param  array<string, mixed>  $envelope
     */
    protected function isStale(array $envelope): bool
    {
        $minutes = $this->maxAgeMinutes();

        if ($minutes <= 0) {
            return false;
        }

        $createdAt = $envelope['createdAt'] ?? null;

        if (! is_string($createdAt) || $createdAt === '') {
            return false;
        }

        try {
            $moment = new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return false;
        }

        return $moment->getTimestamp() < now()->subMinutes($minutes)->getTimestamp();
    }

    protected function maxAgeMinutes(): int
    {
        $configured = config('automations.integrations.cal_com.max_age_minutes', 1440);

        return is_numeric($configured) ? (int) $configured : 1440;
    }

    protected function maxBodyBytes(): int
    {
        $configured = config('automations.integrations.cal_com.max_body_bytes', 262144);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 262144;
    }
}
