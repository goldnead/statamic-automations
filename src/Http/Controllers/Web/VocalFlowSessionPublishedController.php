<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Web;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowDeliveries;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowEvents;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowSignature;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Die zweite Tuer: eine Session ist veroeffentlicht.
 *
 * Sie steht neben {@see VocalFlowWebhookController} und nicht darin, weil
 * VocalFlow sie anders bedient. Das ist keine Geschmacksfrage, sondern in drei
 * Punkten eine andere Naht:
 *
 * **Anderes Verfahren.** Kein HMAC ueber die Nutzlast, sondern ein
 * `Authorization: Bearer <secret>` — und ein anderes Secret als der
 * Ereignis-Kanal, weil es bei VocalFlow ein anderes Abo ist. Beide in einem
 * Controller zu vereinen hiesse, zwei Pruefwege nebeneinanderzulegen und pro
 * Anfrage zu raten, welcher gilt. Ein Empfaenger, der zwei Verfahren kennt und
 * eines davon durchlaesst, wenn das andere nicht passt, hat am Ende gar keines.
 *
 * **Andere Nutzlast.** Kein Umschlag, kein `event`, kein `timestamp`, kein
 * `data`. Zwei Felder: `session_id` und `student_email`. Mehr schickt VocalFlow
 * nicht, und mehr taeuscht dieser Auslöser deshalb auch nicht vor.
 *
 * **Andere Kennung gegen Doppelzustellung.** Beim Ereignis-Kanal ist es der
 * Fingerabdruck der ganzen Nutzlast, weil dort ein `timestamp` mitlaeuft und
 * eine echte zweite Aenderung dadurch anders aussieht als eine Wiederholung.
 * Hier gibt es keinen Zeitstempel, der Fingerabdruck waere fuer dieselbe
 * Session auf ewig gleich. Genommen werden deshalb die beiden Felder, die es
 * gibt: Session und Adresse. Warum nicht die Session allein, steht unten an der
 * Stelle.
 *
 * ## Diese Tuer ist die schwaechere, und das soll hier stehen
 *
 * Ein Bearer-Token ist weniger als eine Signatur, und der Unterschied ist
 * nicht theoretisch. Eine abgefangene Signatur taugt fuer **eine** Nutzlast und
 * nur solange die Altersgrenze sie gelten laesst. Ein abgefangenes Token taugt
 * fuer beliebige Nutzlasten, unbegrenzt: wer es hat, meldet jede Session als
 * veroeffentlicht, an jede Adresse.
 *
 * Der Weg dorthin fuehrt in aller Regel nicht ueber den Draht, sondern ueber
 * Protokolle. Das Token reist bei **jeder** Anfrage im `Authorization`-Header,
 * und genau den schreiben Proxys, WAFs und Ueberwachungswerkzeuge gern mit.
 * Wer diesen Anschluss betreibt, hat deshalb zwei Aufgaben, die kein Code hier
 * uebernehmen kann: den Header aus den Protokollen heraushalten und das Token
 * wechseln koennen.
 *
 * Eine Altersgrenze gibt es hier nicht, weil es nichts Mitsigniertes gibt, an
 * dem sich ein Alter ablesen liesse. Ein mitgeschnittener Aufruf ist damit nach
 * Ablauf des Dedupe-Fensters wieder gueltig; wer das nicht will, setzt
 * `dedupe_minutes` hoch.
 */
class VocalFlowSessionPublishedController
{
    public function __construct(
        protected TriggerDispatcher $dispatcher,
        protected TriggerRegistry $triggers,
        protected VocalFlowDeliveries $deliveries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('automations.integrations.vocalflow.publication_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['status' => 'not_configured'], 503);
        }

        $token = (string) $request->bearerToken();

        // In konstanter Zeit, aus demselben Grund wie bei der Signatur: `===`
        // bricht beim ersten abweichenden Byte ab und verraet ueber die
        // Laufzeit, wie weit man gekommen ist.
        if ($token === '' || ! hash_equals($secret, $token)) {
            return response()->json(['status' => 'invalid_token'], 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        $raw = $request->getContent();

        if (strlen($raw) > $this->maxBodyBytes()) {
            return response()->json(['status' => 'too_large'], 413);
        }

        $payload = json_decode($raw, true, VocalFlowSignature::MAX_DEPTH);

        if (! is_array($payload)) {
            return response()->json(['status' => 'malformed'], 400);
        }

        $sessionId = $payload['session_id'] ?? null;
        $email = $payload['student_email'] ?? null;

        if (! is_string($sessionId) || trim($sessionId) === '' || ! is_string($email)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $sessionId = trim($sessionId);

        // Die Adresse kommt bei VocalFlow schon kleingeschrieben an. Sie hier
        // trotzdem zu normalisieren kostet nichts und nimmt einer Bedingung
        // `student.email ist gleich ...` die Falle, dass sie an einer
        // Grossschreibung scheitert, die niemand sieht.
        $email = strtolower(trim($email));

        // Und geprueft, nicht nur "nicht leer". Der Wert wandert von hier
        // ungefiltert in einen Ablauf, und der haeufigste erste Knoten dahinter
        // schickt eine Mail an genau dieses Feld. Was hier nicht wie eine
        // Adresse aussieht, ist keine, und es abzuweisen ist billiger als ein
        // Ablauf, der an einer Zeichenkette scheitert, die nie eine Adresse
        // war.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $handle = VocalFlowEvents::SESSION_PUBLISHED_HANDLE;

        if (! $this->triggers->has($handle)) {
            return response()->json(['status' => 'ignored'], 202);
        }

        $scope = VocalFlowEvents::SESSION_PUBLISHED_EVENT;

        // Die Kennung traegt beide Felder, nicht nur die Session.
        //
        // Auf die `session_id` allein zu sperren waere die naheliegende Wahl
        // und faellt in eine Falle: wer eine falsche Adresse bemerkt und
        // dieselbe Session mit der richtigen noch einmal veroeffentlicht,
        // bekaeme 200 und der Ablauf liefe nie — die Korrektur waere still
        // verschluckt, und zwar von der Schranke, die eigentlich nur
        // Wiederholungen abfangen soll.
        //
        // Mit beiden Feldern trennen sich die Faelle: dieselbe Zustellung
        // zweimal ist gesperrt, dieselbe Session an eine andere Adresse laeuft.
        $identity = $sessionId.'|'.$email;

        if (! $this->deliveries->firstSeen($scope, $identity)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        // Der Umschlag wird hier gebaut, nicht empfangen. Er traegt dieselben
        // Schluessel wie der des Ereignis-Kanals (`event`, `data`), damit ein
        // Verzweigungsknoten `vocalflow.event` ueber alle sieben Auslöser
        // gleich lesen kann und der Flattener nicht zwei Formen kennen muss.
        $envelope = [
            'event' => $scope,
            'data' => [
                'session' => ['id' => $sessionId],
                'student' => ['email' => $email],
            ],
        ];

        try {
            $this->dispatcher->dispatch($handle, $envelope);
        } catch (Throwable $e) {
            $this->deliveries->release($scope, $identity);

            throw $e;
        }

        return response()->json(['status' => 'ok'], 200);
    }

    protected function maxBodyBytes(): int
    {
        $configured = config('automations.integrations.vocalflow.max_body_bytes', 262144);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 262144;
    }
}
