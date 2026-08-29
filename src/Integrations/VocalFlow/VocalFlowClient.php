<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Der Weg hinaus zu VocalFlows Partner-API.
 *
 * ## Warum dieser Client so klein ist
 *
 * Die Partner-API kann viel: Sessions listen und absagen, Aufgaben listen und
 * abschliessen, Kommentare und Antworten schreiben, Reaktionen setzen,
 * Abgaben hochladen, Fortschritt und Medien lesen. Nichts davon steht hier.
 *
 * Was hier steht, sind die zwei Operationen, die im Onboarding wirklich
 * vorkommen: einen Studenten anlegen und ihm ein Paket gutschreiben. Alles
 * andere waere Vorrat, den heute nichts ruft — und ein ungenutzter Knoten ist
 * kein neutraler Zusatz: er steht im Editor und will bei jeder Aenderung an
 * dieser Datei mitgetestet und mitgepflegt werden. Was fehlt, ist in der
 * Routenliste von VocalFlow nachzulesen und in einer Stunde nachgebaut; was zu
 * viel da ist, faellt niemandem auf, bis es kaputt ist.
 *
 * ## Ein Weg, eine Wahrheit
 *
 * adriangoldner.com spricht dieselbe API und hat dafuer seinen eigenen Client.
 * Der bleibt dort und wird hier nicht kopiert. Zwei Kopien derselben Aufrufe
 * driften auseinander, und die Abweichung faellt erst auf, wenn eine der beiden
 * einen Vorgang anders behandelt als die andere.
 *
 * ## Ohne Zugangsdaten passiert nichts
 *
 * `isConfigured()` ist keine Hoeflichkeit, sondern die Bedingung. Ohne Adresse
 * und Geheimnis wuerde ein Aufruf ins Leere gehen und der Ablauf bekaeme eine
 * Fehlermeldung ueber einen Verbindungsfehler, wo eigentlich "hier ist nichts
 * eingerichtet" gemeint ist. Die Aktionen fragen deshalb vorher.
 *
 * ## Kein eigener Wiederholungsversuch
 *
 * Der Client wiederholt nichts. Zwei Gruende: die Ablauf-Maschine hat ihre
 * eigene Wiederholung je Knoten, und zwei uebereinandergelegte Wiederholungen
 * multiplizieren sich zu einer Zahl, die niemand eingestellt hat. Und
 * "Paket gutschreiben" ist ohne `Idempotency-Key` **nicht** wiederholbar: ein
 * blinder zweiter Versuch nach einer Zeitueberschreitung schreibt das Paket ein
 * zweites Mal gut, und der Student hat sechs Stunden statt drei.
 */
class VocalFlowClient
{
    /**
     * Der Pfad, unter dem VocalFlow die Partner-API fuehrt.
     *
     * Fest und nicht konfigurierbar: er gehoert zur API-Version und nicht zur
     * Installation. Wer eines Tages `partner/v2` bedienen will, baut einen
     * zweiten Client, weil sich mit der Version auch die Nutzlasten aendern.
     */
    protected const BASE_PATH = '/api/partner/v1';

    /**
     * Sind Adresse und Geheimnis hinterlegt?
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->secret() !== null;
    }

    /**
     * Einen Studenten anlegen, oder den vorhandenen zurueckbekommen.
     *
     * VocalFlow sucht selbst zuerst nach der Adresse und legt nur an, wenn es
     * keinen gibt; `data.created` sagt, was von beidem passiert ist. Der Aufruf
     * ist damit gefahrlos wiederholbar, anders als der zweite unten.
     *
     * VocalFlow ordnet dem neuen Studenten ausserdem einen Coach zu. Gibt es
     * keinen, wird der Student trotzdem angelegt und `data.coach_assigned` ist
     * `false` — das ist kein Fehler, aber der Grund, warum das Feld hier
     * durchgereicht wird: ein Student ohne Coach kann nichts buchen, und ein
     * Ablauf soll das melden koennen.
     */
    public function createStudent(string $email, string $name): VocalFlowResult
    {
        return $this->post('/students', [
            'email' => $email,
            'name' => $name,
        ]);
    }

    /**
     * Einem Studenten ein Paket gutschreiben.
     *
     * **Nicht von sich aus wiederholbar.** Ohne `Idempotency-Key` legt jeder
     * Aufruf einen neuen Kauf an. Mit einem Schluessel antwortet VocalFlow beim
     * zweiten Mal mit demselben Kauf und `data.created` gleich `false`.
     *
     * Die Adresse steht im Pfad und wird deshalb kodiert. Ein `+` in einer
     * Adresse (`nina+chor@example.com`) ist zulaessig und in einem URL-Pfad
     * etwas anderes als ein Leerzeichen; ohne Kodierung sucht VocalFlow nach
     * `nina chor@example.com` und antwortet 404 auf einen Studenten, den es
     * gibt.
     *
     * @param  array<string, mixed>  $package
     */
    public function grantPackage(string $email, array $package, ?string $idempotencyKey = null): VocalFlowResult
    {
        $headers = is_string($idempotencyKey) && $idempotencyKey !== ''
            ? ['Idempotency-Key' => $idempotencyKey]
            : [];

        return $this->post('/students/'.rawurlencode($email).'/package-purchases', $package, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function post(string $path, array $payload, array $headers = []): VocalFlowResult
    {
        $base = $this->baseUrl();
        $secret = $this->secret();

        if ($base === null || $secret === null) {
            return VocalFlowResult::failure(0, 'VocalFlow is not configured: set the partner URL and the partner secret.');
        }

        try {
            $response = $this->request($secret, $headers)->post($base.self::BASE_PATH.$path, $payload);
        } catch (Throwable $e) {
            // Es kam nicht bis zu einer Antwort: Netz, Namensaufloesung, TLS,
            // Zeitueberschreitung. Status 0 haelt das von einem abgelehnten
            // Aufruf auseinander.
            return VocalFlowResult::failure(0, 'VocalFlow could not be reached: '.$e->getMessage());
        }

        if ($response->failed()) {
            return VocalFlowResult::failure($response->status(), $this->errorOf($response));
        }

        $data = $response->json('data');

        return VocalFlowResult::success($response->status(), is_array($data) ? $data : []);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function request(string $secret, array $headers): PendingRequest
    {
        return Http::withToken($secret)
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout());
    }

    /**
     * Eine Fehlermeldung, die im Ablaufprotokoll etwas taugt.
     *
     * VocalFlow antwortet in drei Formen, und alle drei muessen lesbar
     * ankommen: `{"error":"STUDENT_NOT_FOUND"}` bei einem fachlichen Fehler,
     * `{"message":…,"errors":{…}}` bei einer abgelehnten Validierung, und bei
     * einem Serverfehler unter Umstaenden HTML. Der Rueckfall auf den
     * Statuscode ist deshalb nicht Zierde: ohne ihn stuende im Protokoll eine
     * leere Zeile, und "der Knoten ist rot, und es steht nichts dabei" ist die
     * teuerste Sorte Fehler.
     */
    protected function errorOf(Response $response): string
    {
        $status = $response->status();

        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            return "VocalFlow rejected the request ({$status}): {$error}";
        }

        $message = $response->json('message');
        $fields = $response->json('errors');

        if (is_string($message) && $message !== '') {
            if (is_array($fields) && $fields !== []) {
                $flat = [];

                foreach ($fields as $field => $messages) {
                    $messages = is_array($messages) ? $messages : [$messages];

                    foreach ($messages as $text) {
                        if (is_string($text) && $text !== '') {
                            $flat[] = $field.': '.$text;
                        }
                    }
                }

                if ($flat !== []) {
                    return "VocalFlow rejected the request ({$status}): ".implode(' ', $flat);
                }
            }

            return "VocalFlow rejected the request ({$status}): {$message}";
        }

        return "VocalFlow rejected the request ({$status}).";
    }

    /**
     * Die Wurzel-Adresse, ohne abschliessenden Schraegstrich und ohne den
     * Pfad der Partner-API.
     */
    protected function baseUrl(): ?string
    {
        $url = config('automations.integrations.vocalflow.partner_url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim(trim($url), '/');
    }

    protected function secret(): ?string
    {
        $secret = config('automations.integrations.vocalflow.partner_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    protected function timeout(): int
    {
        $configured = config('automations.integrations.vocalflow.timeout', 10);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 10;
    }
}
