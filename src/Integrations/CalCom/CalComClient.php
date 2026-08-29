<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom;

use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Der Weg hinaus zu cal.coms API v2.
 *
 * ## Die eine Sache, die man ueber diese API wissen muss
 *
 * cal.com versioniert **je Endpunkt**, ueber die Kopfzeile `cal-api-version`,
 * und die richtige Version ist fuer jeden Endpunkt eine andere. Es gibt keine
 * Version, die fuer alle passt.
 *
 * Bei der falschen Version antwortet cal.com nicht mit 400, sondern auf drei
 * verschiedene Weisen, und zwei davon sind still (gemessen am 29.08.2026):
 *
 *   - `/v2/slots` mit `2024-06-14` **oder ganz ohne Kopfzeile**: 404
 *     `Cannot GET /v2/slots`. Laut, aber irrefuehrend, denn es liest sich wie
 *     "diesen Endpunkt gibt es nicht".
 *   - `/v2/event-types` mit `2024-08-13`: ebenfalls 404. Ohne Kopfzeile
 *     dagegen: **200** mit einer voellig anderen Form (`eventTypeGroups`
 *     statt einer Liste).
 *   - `/v2/bookings` mit `2024-06-14`: **200** mit einer anderen Form und
 *     einer leeren Liste. Wer so fragt, bekommt "keine Termine" und keinen
 *     Hinweis darauf, dass er falsch gefragt hat.
 *
 * Die letzten beiden sind der Grund fuer den Zuschnitt dieser Klasse. Eine
 * gemeinsame Kopfzeile im Konstruktor waere die naheliegende Loesung und die
 * falsche: sie waere fuer einen der Endpunkte immer verkehrt, und der Fehler
 * kaeme als leeres Ergebnis zurueck, nicht als Fehler.
 *
 * Deshalb ist die Version hier ein **Pflichtargument** von {@see send()} und
 * steht als Konstante unmittelbar ueber der Operation, die sie braucht. Wer
 * eine Operation ergaenzt, muss sich entscheiden; auslassen geht nicht.
 *
 * Auslassen geht nicht, **verwechseln** schon: `self::VERSION_BOOKINGS` an
 * einem Terminart-Pfad ist syntaktisch einwandfrei und ergibt einen 404. Dass
 * das auffaellt, ist deshalb kein Verdienst dieser Datei, sondern Aufgabe des
 * Tests: `CalComActionsTest::test_every_operation_sends_the_version_its_endpoint_wants`
 * laeuft jede Operation gegen eine Attrappe, die die Kopfzeile so streng
 * nimmt wie das Original, und haelt fest, welche Version an welchem Pfad
 * ankam. Ein Kommentar, der Sicherheit behauptet, waere hier das Gegenteil
 * davon.
 *
 * ## Warum nur diese Operationen
 *
 * Dieselbe Ueberlegung wie beim Nachbarn
 * {@see VocalFlowClient}:
 * gebaut ist, was ein Ablauf heute ruft. Die API v2 kann daneben Termine
 * verlegen, bestaetigen, ablehnen, Terminarten anlegen und aendern,
 * Verfuegbarkeiten schreiben, Teams verwalten. Nichts davon steht hier. Ein
 * ungenutzter Knoten ist kein neutraler Zusatz: er steht im Editor und will
 * bei jeder Aenderung an dieser Datei mitgetestet werden.
 *
 * ## Kein eigener Wiederholungsversuch
 *
 * Wie drueben: die Ablauf-Maschine hat ihre eigene Wiederholung je Knoten, und
 * zwei uebereinandergelegte multiplizieren sich zu einer Zahl, die niemand
 * eingestellt hat.
 *
 * Was dazugehoert und beim Nachbarn nicht gesagt werden musste: die
 * Wiederholung der Ablauf-Maschine ist **blind**. Sie schickt einen roten
 * Knoten sofort noch einmal hinaus, ohne den Statuscode anzusehen. Und cal.com
 * kennt fuer **keine** der schreibenden Operationen einen
 * Idempotenz-Schluessel. Ein zweiter Versuch nach einer Zeitueberschreitung
 * ist damit nicht harmlos, und keine Stelle in diesem Code haelt ihn auf.
 *
 * Was ihn aufhaelt, ist cal.com selbst: die Anlage antwortet auf einen belegten
 * Zeitpunkt mit 409, die Absage auf einen abgesagten Termin mit 400. Der
 * Schutz kommt also von drueben, nicht von hier, und er reicht nur so weit, wie
 * die zweite Anfrage dieselbe ist wie die erste. Wer die Absage-Meldung eines
 * Ablaufs nicht verlieren will, setzt an den schreibenden Knoten
 * `_retry_attempts` auf 0; warum, steht in {@see Actions\CancelBookingAction}.
 *
 * Die cURL-Meldung einer Zeitueberschreitung wandert deshalb unveraendert ins
 * Protokoll. Sie ist die einzige Auskunft darueber, ob die Anfrage draussen
 * war, und sie ist fuer einen Menschen gedacht: kein Codepfad wertet sie aus,
 * weil sich aus ihr keine sichere Entscheidung ableiten laesst.
 */
class CalComClient
{
    /**
     * Die Wurzel der API. Nicht der Host der eigenen Seite und nicht der von
     * `app.cal.com`; die API sitzt auf `api.cal.com`.
     */
    protected const DEFAULT_BASE_URL = 'https://api.cal.com';

    /**
     * Sind die Zugangsdaten hinterlegt?
     *
     * Wie drueben keine Hoeflichkeit, sondern die Bedingung: ohne Schluessel
     * ginge der Aufruf ins Leere, und der Ablauf bekaeme eine Meldung ueber
     * einen abgelehnten Zugriff, wo "hier ist nichts eingerichtet" gemeint ist.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    // --- Termine ------------------------------------------------------------

    /**
     * Die Version, die die Termin-Endpunkte verlangen.
     *
     * Gilt fuer `POST /v2/bookings`, `GET /v2/bookings/{uid}` und
     * `POST /v2/bookings/{uid}/cancel`. Mit `2024-06-14` antwortet die Liste
     * `GET /v2/bookings` **200 mit einer leeren, anders geformten Antwort**;
     * das ist die stille Variante des Fehlers und der Grund, warum diese
     * Konstante hier steht und nicht in einer gemeinsamen Kopfzeile.
     */
    protected const VERSION_BOOKINGS = '2024-08-13';

    /**
     * Einen Termin absagen.
     *
     * **Nicht idempotent, aber laut.** Der zweite Aufruf auf denselben Termin
     * antwortet 400 `BadRequestException` mit "because it has been cancelled
     * already" (geprueft am 29.08.2026 gegen einen echten Termin). Es entsteht
     * also kein Schaden durch einen doppelten Lauf, wohl aber ein roter Knoten
     * fuer etwas, das genau so gewollt war. Wie die Aktion damit umgeht, steht
     * in {@see Actions\CancelBookingAction}.
     */
    public function cancelBooking(string $uid, string $reason): CalComResult
    {
        if (trim($uid) === '') {
            return self::missingIdentifier('booking uid');
        }

        return $this->send('post', '/v2/bookings/'.rawurlencode($uid).'/cancel', self::VERSION_BOOKINGS, [
            'cancellationReason' => $reason,
        ]);
    }

    /**
     * Einen Termin lesen.
     *
     * Nur fuer eine Sache da: nach einer abgelehnten Absage nachzusehen, wie
     * der Zustand drueben wirklich ist. Es gibt keinen Knoten dafuer, und es
     * soll auch keiner daraus werden, solange kein Ablauf ihn ruft.
     */
    public function booking(string $uid): CalComResult
    {
        if (trim($uid) === '') {
            return self::missingIdentifier('booking uid');
        }

        return $this->send('get', '/v2/bookings/'.rawurlencode($uid), self::VERSION_BOOKINGS);
    }

    /**
     * Einen Termin anlegen.
     *
     * **Kein Idempotenz-Schluessel.** cal.com bietet fuer diesen Endpunkt
     * keinen an. Was einen doppelten Lauf trotzdem meistens abfaengt, ist der
     * Kalender selbst: derselbe Zeitpunkt ein zweites Mal ergibt 409
     * `ConflictException` "User either already has booking at this time or is
     * not available" und **keinen** zweiten Termin (geprueft am 29.08.2026 mit
     * zwei echten Anlagen auf denselben Slot).
     *
     * Das gilt, solange der Zeitpunkt derselbe ist. Ein Ablauf, der ihn
     * ausrechnet statt ihn mitzubringen, rechnet beim zweiten Lauf einen
     * anderen aus und legt dann sehr wohl einen zweiten Termin an. Der Schutz
     * kommt vom Kalender, nicht von dieser API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createBooking(array $payload): CalComResult
    {
        return $this->send('post', '/v2/bookings', self::VERSION_BOOKINGS, $payload);
    }

    // --- Freie Zeiten -------------------------------------------------------

    /**
     * Die Version, die `GET /v2/slots` verlangt.
     *
     * Eine andere als bei den Terminen. Mit `2024-06-14` oder ohne Kopfzeile
     * antwortet der Endpunkt 404 `Cannot GET /v2/slots`.
     */
    protected const VERSION_SLOTS = '2024-09-04';

    /**
     * Freie Zeiten holen.
     *
     * Liest nur und aendert nichts, ein doppelter Lauf ist folgenlos.
     *
     * Die Antwort ist nach Datum geschluesselt:
     * `{"2026-09-01": [{"start": "..."}], ...}`. Eine **unbekannte** Terminart
     * ergibt dabei nicht etwa einen Fehler, sondern `{}` — dieselbe Antwort wie
     * ein ausgebuchter Kalender. Wer das nicht auseinanderhaelt, baut einen
     * Ablauf, der still nichts vorschlaegt. {@see Actions\GetSlotsAction} haelt
     * es auseinander, mit {@see eventType()}.
     *
     * @param  array<string, mixed>  $query
     */
    public function slots(array $query): CalComResult
    {
        return $this->send('get', '/v2/slots', self::VERSION_SLOTS, $query);
    }

    // --- Terminarten --------------------------------------------------------

    /**
     * Die Version, die die Terminart-Endpunkte verlangen.
     *
     * Die dritte in dieser Datei. Mit `2024-08-13` antwortet
     * `GET /v2/event-types` 404; **ohne** Kopfzeile antwortet er 200 mit einer
     * ganz anderen Form (`eventTypeGroups` statt einer Liste). Der zweite Fall
     * ist der gefaehrliche: er sieht aus wie ein Erfolg.
     */
    protected const VERSION_EVENT_TYPES = '2024-06-14';

    /**
     * Eine Terminart lesen.
     *
     * Kein eigener Knoten, sondern die Gegenprobe zu einer leeren Antwort von
     * {@see slots()}: gibt es diese Terminart ueberhaupt? Ein unbekannte
     * Kennung antwortet hier 404 `Event type with id ... not found`, und damit
     * laesst sich "die Kennung ist falsch" von "der Kalender ist voll"
     * trennen.
     *
     * Dass daraus kein Knoten "Terminarten holen" geworden ist, ist eine
     * Entscheidung: die Kennung einer Terminart ist ein fester Wert in der
     * Einrichtung eines Ablaufs und nichts, was zur Laufzeit gesucht wird. Ein
     * Knoten, der eine Liste holt, die niemand liest, steht trotzdem im Editor.
     */
    public function eventType(string $id): CalComResult
    {
        if (trim($id) === '') {
            return self::missingIdentifier('event type id');
        }

        return $this->send('get', '/v2/event-types/'.rawurlencode($id), self::VERSION_EVENT_TYPES);
    }

    /**
     * Eine leere Kennung wird hier abgewiesen und nicht in die URL geschrieben.
     *
     * Kein Schoenheitsfehler: `/v2/bookings//cancel` antwortet nicht "gibt es
     * nicht", sondern mit einer Weiterleitung (308, gemessen am 29.08.2026),
     * und was danach kommt, ist eine ganz andere Route. Die Meldung, die
     * daraus entstuende, spraeche vom Endpunkt oder von der Version, waehrend
     * in Wahrheit ein Feld im Ablauf leer geblieben ist — der haeufigste
     * Betriebsfehler ueberhaupt, und der einzige, den diese Meldung nicht
     * nennen wuerde.
     */
    protected static function missingIdentifier(string $what): CalComResult
    {
        return CalComResult::failure(0, "No {$what} to act on: the value is empty, so no request was made.");
    }

    // --- Der Weg hinaus -----------------------------------------------------

    /**
     * Eine Anfrage an cal.com, mit **der** Version, die dieser Endpunkt will.
     *
     * `$version` ist Pflicht und hat bewusst keine Vorgabe. Eine Vorgabe waere
     * fuer mindestens einen der drei Endpunkte falsch, und weil cal.com bei
     * der falschen Version teils mit 200 und einer anderen Form antwortet,
     * faellt so ein Fehler beim Bauen nicht auf, sondern erst im Betrieb, als
     * "es kommt nichts zurueck".
     *
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $path, string $version, array $payload = []): CalComResult
    {
        $key = $this->apiKey();

        if ($key === null) {
            return CalComResult::failure(0, 'cal.com is not configured: set the API key.');
        }

        try {
            $request = Http::withToken($key)
                ->withHeaders(['cal-api-version' => $version])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout());

            $response = $method === 'get'
                ? $request->get($this->baseUrl().$path, $payload)
                : $request->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException $e) {
            // Es kam nicht bis zu einer Antwort. Status 0 haelt das von einer
            // Ablehnung auseinander.
            //
            // Gefangen wird ausdruecklich nur diese Ausnahme und nicht
            // `Throwable`. Ein `TypeError` aus diesem Code ist kein
            // unerreichbarer Dienst, und ihn als einen zu melden hiesse, den
            // eigenen Fehler cal.com anzuhaengen; er darf hochsteigen und von
            // der Ablauf-Maschine als das behandelt werden, was er ist.
            //
            // Die cURL-Meldung wandert unveraendert mit, weil in ihr die
            // einzige Auskunft steckt, die hier zaehlt: **war die Anfrage
            // draussen?** "Could not resolve host" und "Failed to connect"
            // heissen nein, ein Wiederholen ist gefahrlos. "Operation timed
            // out" heisst vielleicht, und weil cal.com fuer keine schreibende
            // Operation einen Idempotenz-Schluessel kennt, kann ein
            // Wiederholen dann einen zweiten Termin anlegen. Diesen
            // Unterschied wegzuwischen waere das Gegenteil dessen, was der
            // Verzicht auf eine eigene Wiederholung bezweckt.
            return CalComResult::failure(0, 'cal.com could not be reached: '.$e->getMessage());
        }

        if ($response->failed()) {
            return $this->failureFor($response, $path, $version);
        }

        return $this->successFor($response, $path);
    }

    /**
     * Eine angenommene Antwort, aber erst nachdem sie sich als eine von
     * cal.com ausgewiesen hat.
     *
     * v2 antwortet immer im selben Umschlag: `{"status":"success","data":…}`.
     * Wer nur `data` herausgreift und alles andere durchwinkt, macht aus jeder
     * 200, die nicht von cal.com stammt, einen leeren Erfolg — und leer statt
     * falsch ist die Fehlerform, die am laengsten unentdeckt bleibt. Der Fall
     * ist nicht erfunden: steht `api_url` auf `cal.com` statt `api.cal.com`,
     * folgt der Client der Weiterleitung und bekommt eine HTML-Seite mit
     * Status 200.
     *
     * Was dieser Umschlag **nicht** faengt, muss dazugesagt werden: die
     * gefaehrlichste Folge einer falschen `cal-api-version` ist eine Antwort,
     * die den Umschlag korrekt traegt und darin etwas anderes transportiert
     * (`data.bookings` statt einer Liste, `data.eventTypeGroups` statt einer
     * Terminart). Dagegen hilft keine Pruefung an dieser Stelle, sondern nur
     * die Regel in den Aktionen: kein Erfolg ohne das Feld, das den Erfolg
     * belegt — die uid des neuen Termins, der Zustand `cancelled` des
     * abgesagten.
     */
    protected function successFor(Response $response, string $path): CalComResult
    {
        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'success' || ! array_key_exists('data', $body)) {
            return CalComResult::failure(
                $response->status(),
                "cal.com answered {$response->status()} for {$path}, but not in the shape its v2 API answers in "
                    .'(no "status": "success" and no "data"). Something other than the API answered here, so this is not '
                    .'an empty result but an unanswered request.',
                recognised: false,
            );
        }

        $data = $body['data'];

        return CalComResult::success($response->status(), is_array($data) ? $data : []);
    }

    /**
     * Aus einer abgelehnten Antwort eine Meldung machen, die an der richtigen
     * Stelle suchen laesst.
     *
     * cal.com antwortet auf einen Fehler mit
     * `{"status":"error","error":{"code":…,"message":…}}`. Die Meldung darin
     * ist brauchbar und wandert unveraendert ins Ablaufprotokoll.
     *
     * Der Sonderfall ist der **404 aus einer falschen Version**. Er sieht
     * genauso aus wie ein 404 fuer einen Termin, den es nicht gibt, und wer
     * die beiden verwechselt, sucht tagelang im falschen System. Sie lassen
     * sich aber an der Form der Meldung unterscheiden, und zwar strukturell
     * statt an einem Stichwort:
     *
     *   - Version falsch: `Cannot GET /v2/slots` — der Routenname selbst.
     *   - Wirklich nicht da: `Booking with uid=… not found`,
     *     `Event type with id … not found` — die Sache, nach der gefragt wurde.
     *
     * Der Vergleich haengt am Praefix `Cannot <METHODE> `, das der
     * darunterliegende Server (NestJS) fuer eine unbekannte Route bildet, und
     * nicht an einer Formulierung, die cal.com morgen umschreibt.
     */
    protected function failureFor(Response $response, string $path, string $version): CalComResult
    {
        $status = $response->status();
        $message = $response->json('error.message');
        $message = is_string($message) && $message !== '' ? $message : null;
        $code = $response->json('error.code');
        $code = is_string($code) && $code !== '' ? $code : null;

        // Hat cal.com selbst geantwortet? Nur dann taugt der Statuscode als
        // fachliche Auskunft. Ein 404 von einem Proxy oder einer falsch
        // eingetragenen Adresse ist HTML und heisst nicht "diese Terminart
        // gibt es nicht", sondern "wir haben mit cal.com gar nicht
        // gesprochen".
        $recognised = $response->json('status') === 'error' && ($message !== null || $code !== null);

        if ($status === 404 && $message !== null && preg_match('/^Cannot [A-Z]+ /', $message) === 1) {
            return CalComResult::failure(
                $status,
                "cal.com does not know the route {$path} (asked with cal-api-version {$version}): {$message}. "
                    .'The likeliest cause is that header: it is per endpoint, and the wrong one answers 404 rather '
                    .'than 400. A wrong api_url looks the same from here.',
                versionMismatch: true,
            );
        }

        if ($message !== null) {
            return CalComResult::failure($status, "cal.com rejected the request ({$status}): {$message}", recognised: $recognised);
        }

        if ($code !== null) {
            return CalComResult::failure($status, "cal.com rejected the request ({$status}): {$code}", recognised: $recognised);
        }

        // Der Rueckfall ist nicht Zierde: ohne ihn stuende bei einem
        // Serverfehler, der HTML liefert, eine leere Zeile im Protokoll, und
        // "der Knoten ist rot, und es steht nichts dabei" ist die teuerste
        // Sorte Fehler.
        //
        // `error.details` wird bewusst nicht ausgewertet. Der Nachbar
        // VocalFlowClient flacht dort ein `errors`-Feld je Feld ab, weil es
        // dort etwas enthaelt, das in `message` fehlt. Bei cal.com wiederholt
        // `details.message` nur `error.message` woertlich (nachgesehen am
        // 29.08.2026 an einem echten 400: "attendee property is wrong,
        // attendee should not be null or undefined" steht in beiden). Es
        // auszulesen brachte dieselbe Zeile zweimal.
        return CalComResult::failure($status, "cal.com rejected the request ({$status}).", recognised: false);
    }

    protected function apiKey(): ?string
    {
        $key = config('automations.integrations.cal_com.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    protected function baseUrl(): string
    {
        $url = config('automations.integrations.cal_com.api_url', self::DEFAULT_BASE_URL);

        return is_string($url) && trim($url) !== ''
            ? rtrim(trim($url), '/')
            : self::DEFAULT_BASE_URL;
    }

    protected function timeout(): int
    {
        $configured = config('automations.integrations.cal_com.timeout', 10);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 10;
    }
}
