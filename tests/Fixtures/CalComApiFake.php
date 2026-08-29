<?php

namespace Goldnead\StatamicAutomations\Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Ein cal.com, das so streng ist wie das echte.
 *
 * ## Warum diese Klasse ueberhaupt existiert
 *
 * Eine Attrappe, die auf jede Anfrage dieselbe schoene Antwort gibt, belegt
 * nur, dass ein Aufruf stattfand. Sie belegt nicht, dass er richtig war. Bei
 * cal.com ist das kein akademischer Einwand, sondern der ganze Punkt des
 * Anschlusses: die API verlangt je Endpunkt eine **andere**
 * `cal-api-version`, und bei der falschen antwortet sie nicht mit 400,
 * sondern je nach Endpunkt mit 404 oder mit 200 in einer voellig anderen
 * Form. Eine nachgiebige Attrappe wuerde einen Client mit falscher Kopfzeile
 * gruen durchwinken, und der Fehler faende sich erst im Betrieb, als "es kommt
 * nichts zurueck".
 *
 * Diese Attrappe prueft deshalb die Kopfzeile bei jedem Aufruf und antwortet
 * bei der falschen so wie das Original.
 *
 * ## Was gemessen ist und was Hausregel ist
 *
 * Gemessen am 29.08.2026 gegen die echte API mit Adrians Schluessel:
 *
 *   - `GET /v2/slots` mit `2024-09-04`: 200. Mit `2024-06-14` oder ohne
 *     Kopfzeile: 404 `Cannot GET /v2/slots`.
 *   - `GET /v2/event-types` mit `2024-06-14`: 200. Mit `2024-08-13`: 404
 *     `Cannot GET /v2/event-types`. **Ohne** Kopfzeile: 200 mit
 *     `eventTypeGroups` statt einer Liste.
 *   - `GET /v2/bookings` mit `2024-08-13`: 200 mit einer Liste. Mit
 *     `2024-06-14`: **200**, korrekter Umschlag
 *     (`{"status":"success","data":…}`) und darin eine ganz andere Form:
 *     `{"bookings":[],"totalCount":0,…}` statt der Liste. Das ist die
 *     stille Form des Fehlers, und sie ist der Grund, warum eine Pruefung des
 *     Umschlags allein nicht reicht: der Umschlag stimmt.
 *   - `POST /v2/bookings/{uid}/cancel` auf einen schon abgesagten Termin: 400
 *     `BadRequestException`, "because it has been cancelled already".
 *   - `POST /v2/bookings` auf einen belegten Zeitpunkt: 409
 *     `ConflictException`.
 *   - Unbekannte Kennungen: 404 `Booking with uid=… not found` bzw.
 *     `Event type with id … not found`.
 *
 * Hausregel, also strenger als das Original: die **schreibenden**
 * Termin-Endpunkte antworten hier bei falscher Version mit der stillen
 * 200-Form der Termin-Familie. Am echten Dienst laesst sich die Route
 * `/cancel` auch mit `2024-06-14` erreichen; welche Form sie dann
 * zurueckgibt, ist nicht gemessen und wird hier nicht behauptet. Was diese
 * Attrappe modelliert, ist die belegte Eigenschaft der Familie: eine falsche
 * Version fuehrt dort nicht zu einem Fehler, sondern zu einer Antwort, die
 * gut aussieht und nichts enthaelt. Ein Client, der das ueberlebt, ueberlebt
 * auch das Original.
 */
class CalComApiFake
{
    public const BASE = 'https://api.cal.test';

    public const KEY = 'cal_live_ein-api-schluessel';

    public const VERSION_BOOKINGS = '2024-08-13';

    public const VERSION_SLOTS = '2024-09-04';

    public const VERSION_EVENT_TYPES = '2024-06-14';

    /**
     * Termine, nach uid. Jeder Eintrag ist ein Termin, wie cal.com ihn
     * zurueckgibt.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $bookings = [];

    /**
     * Terminarten, nach Kennung. Der Wert zaehlt nicht, nur ob es sie gibt.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $eventTypes = [];

    /**
     * Freie Zeiten je Terminart, in cal.coms Form: Datum => Liste von
     * Objekten mit `start`.
     *
     * @var array<string, array<string, list<array{start: string}>>>
     */
    protected array $slots = [];

    /**
     * Belegte Plaetze, als `terminart@zeitpunkt`. Ein `POST /v2/bookings`
     * darauf antwortet 409, so wie das Original.
     *
     * @var list<string>
     */
    protected array $taken = [];

    /**
     * Was jeder Aufruf an `cal-api-version` mitgebracht hat, in der
     * Reihenfolge. Damit ein Test die Kopfzeile pruefen kann, ohne sich durch
     * `Http::assertSent` zu haengeln.
     *
     * @var list<array{method: string, path: string, version: ?string}>
     */
    public array $calls = [];

    public function booking(string $uid, array $attributes = []): self
    {
        $this->bookings[$uid] = array_merge([
            'id' => 24485601,
            'uid' => $uid,
            'title' => 'Discovery Call',
            'status' => 'accepted',
            'start' => '2026-12-14T10:30:00.000Z',
            'end' => '2026-12-14T11:00:00.000Z',
            'eventTypeId' => 5784955,
            'meetingUrl' => 'https://app.cal.com/video/'.$uid,
            'cancellationReason' => '',
        ], $attributes);

        return $this;
    }

    public function eventType(string $id, array $attributes = []): self
    {
        $this->eventTypes[$id] = array_merge([
            'id' => (int) $id,
            'slug' => 'discovery',
            'lengthInMinutes' => 30,
        ], $attributes);

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $byDate
     */
    public function slotsFor(string $eventTypeId, array $byDate): self
    {
        // In cal.coms Form gebracht, nicht in der bequemen: die API antwortet
        // je Tag mit einer Liste von Objekten, die einen `start` tragen, nicht
        // mit einer Liste von Zeichenketten. Eine Attrappe, die hier die
        // bequeme Form ausgibt, prueft das Auspacken im Knoten nicht mit.
        $this->slots[$eventTypeId] = array_map(
            fn (array $starts) => array_map(fn (string $start) => ['start' => $start], $starts),
            $byDate,
        );

        return $this;
    }

    public function taken(string $eventTypeId, string $start): self
    {
        $this->taken[] = $eventTypeId.'@'.$start;

        return $this;
    }

    public function bookingStatus(string $uid): ?string
    {
        return $this->bookings[$uid]['status'] ?? null;
    }

    /**
     * Die Attrappe scharf schalten und die Konfiguration darauf zeigen lassen.
     */
    public function install(): self
    {
        config()->set('automations.integrations.cal_com.api_key', self::KEY);
        config()->set('automations.integrations.cal_com.api_url', self::BASE);

        Http::fake([
            self::BASE.'/*' => fn (Request $request) => $this->answer($request),
        ]);

        return $this;
    }

    protected function answer(Request $request): Response|PromiseInterface
    {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $version = $request->header('cal-api-version')[0] ?? null;
        $method = strtoupper($request->method());

        $this->calls[] = ['method' => $method, 'path' => $path, 'version' => $version];

        // Ein Schluessel muss dabei sein, und zwar der richtige. Ohne das
        // belegte ein gruener Test nur, dass der Aufruf hinausging.
        if ($request->header('Authorization') !== ['Bearer '.self::KEY]) {
            return Http::response($this->error('UnauthorizedException', 'Invalid Access Token.'), 401);
        }

        if (str_starts_with($path, '/v2/slots')) {
            return $version === self::VERSION_SLOTS
                ? $this->slotsResponse($request)
                : $this->routeMissing($method, $path);
        }

        if (str_starts_with($path, '/v2/event-types')) {
            return $version === self::VERSION_EVENT_TYPES
                ? $this->eventTypeResponse($path)
                : $this->routeMissing($method, $path);
        }

        if (str_starts_with($path, '/v2/bookings')) {
            // Die stille Form: kein Fehler, sondern eine Antwort in der alten
            // Gestalt, ohne `data`. Genau die, an der ein nachgiebiger Client
            // gruen wird und nichts hat.
            return $version === self::VERSION_BOOKINGS
                ? $this->bookingResponse($request, $method, $path)
                : Http::response([
                    'status' => 'success',
                    'data' => ['bookings' => [], 'recurringInfo' => [], 'totalCount' => 0, 'hasMore' => false, 'nextCursor' => null],
                ], 200);
        }

        return $this->routeMissing($method, $path);
    }

    protected function slotsResponse(Request $request): Response|PromiseInterface
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $id = (string) ($query['eventTypeId'] ?? '');

        foreach (['start', 'end'] as $required) {
            if (! isset($query[$required]) || $query[$required] === '') {
                return Http::response(
                    $this->error('BadRequestException', "{$required} property is wrong,{$required} must be a valid ISO 8601 date string "),
                    400,
                );
            }
        }

        // Der Kern der Falle: eine unbekannte Terminart ist hier **kein**
        // Fehler, sondern ein leeres Objekt mit Status 200 — nicht zu
        // unterscheiden von einem vollen Kalender. Genau das prueft die
        // Gegenprobe in GetSlotsAction.
        return Http::response(['data' => $this->slots[$id] ?? new \stdClass, 'status' => 'success'], 200);
    }

    protected function eventTypeResponse(string $path): Response|PromiseInterface
    {
        $id = rawurldecode(basename($path));

        if (! isset($this->eventTypes[$id])) {
            return Http::response($this->error('NotFoundException', "Event type with id {$id} not found"), 404);
        }

        return Http::response(['status' => 'success', 'data' => $this->eventTypes[$id]], 200);
    }

    protected function bookingResponse(Request $request, string $method, string $path): Response|PromiseInterface
    {
        if ($method === 'POST' && $path === '/v2/bookings') {
            return $this->create($request->data());
        }

        if ($method === 'POST' && str_ends_with($path, '/cancel')) {
            return $this->cancel(rawurldecode(basename(dirname($path))), $request->data());
        }

        if ($method === 'GET') {
            $uid = rawurldecode(basename($path));

            return isset($this->bookings[$uid])
                ? Http::response(['status' => 'success', 'data' => $this->bookings[$uid]], 200)
                : Http::response($this->error('NotFoundException', "Booking with uid={$uid} not found"), 404);
        }

        return $this->routeMissing($method, $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function create(array $payload): Response|PromiseInterface
    {
        $eventTypeId = (string) ($payload['eventTypeId'] ?? '');
        $start = (string) ($payload['start'] ?? '');

        // So streng wie das Original, und das ist keine Kuer. Eine Attrappe,
        // die jede Nutzlast annimmt, laesst einen Knoten gruen werden, der die
        // Adresse gar nicht mitschickt — und belegt dann nur, dass ein Aufruf
        // stattfand. cal.com lehnt genau das mit 400 ab; der Wortlaut unten ist
        // der echte, gemessen am 29.08.2026 an einer Anlage ohne `attendee`.
        $attendee = $payload['attendee'] ?? null;

        if (! is_array($attendee)) {
            return Http::response(
                $this->error('BadRequestException', 'attendee property is wrong,attendee should not be null or undefined '),
                400,
            );
        }

        foreach (['name', 'email', 'timeZone'] as $required) {
            if (! isset($attendee[$required]) || ! is_string($attendee[$required]) || trim($attendee[$required]) === '') {
                return Http::response(
                    $this->error('BadRequestException', "attendee.{$required} property is wrong,attendee.{$required} should not be empty "),
                    400,
                );
            }
        }

        if ($start === '') {
            return Http::response(
                $this->error('BadRequestException', 'start property is wrong,start must be a valid ISO 8601 date string '),
                400,
            );
        }

        if (! isset($this->eventTypes[$eventTypeId])) {
            return Http::response($this->error('NotFoundException', "Event type with id {$eventTypeId} not found"), 404);
        }

        // Der zweite Lauf desselben Ablaufs: derselbe Zeitpunkt ist belegt.
        // Nach Terminart **und** Zeitpunkt geschluesselt: ein Kalender, der
        // jede zweite Terminart zur selben Stunde ablehnt, waere strenger als
        // das Original, und wer den Konfliktfall spaeter verfeinert, maesse
        // gegen ein falsches Modell.
        $slot = $eventTypeId.'@'.$start;

        if (in_array($slot, $this->taken, true)) {
            return Http::response(
                $this->error('ConflictException', 'User either already has booking at this time or is not available'),
                409,
            );
        }

        $this->taken[] = $slot;

        $uid = 'uid-'.count($this->bookings);

        $this->booking($uid, [
            'start' => $start,
            'eventTypeId' => (int) $eventTypeId,
            // Zurueckgegeben, damit ein Test nachsehen kann, was wirklich
            // angekommen ist, statt nur, dass etwas ankam.
            'attendees' => [$attendee],
            // Woran der `pending`-Fall haengt: an der Terminart, nicht am
            // Aufruf. Genau wie drueben.
            'status' => ($this->eventTypes[$eventTypeId]['confirmationPolicy']['type'] ?? null) === 'always'
                ? 'pending'
                : 'accepted',
        ]);

        return Http::response(['status' => 'success', 'data' => $this->bookings[$uid]], 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function cancel(string $uid, array $payload): Response|PromiseInterface
    {
        if (! isset($this->bookings[$uid])) {
            return Http::response($this->error('NotFoundException', "Booking with uid={$uid} not found"), 404);
        }

        if ($this->bookings[$uid]['status'] === 'cancelled') {
            return Http::response(
                $this->error(
                    'BadRequestException',
                    "Can't cancel booking with uid={$uid} because it has been cancelled already. Please provide uid of a booking that is not cancelled.",
                ),
                400,
            );
        }

        $this->bookings[$uid]['status'] = 'cancelled';
        $this->bookings[$uid]['cancellationReason'] = (string) ($payload['cancellationReason'] ?? '');

        return Http::response(['status' => 'success', 'data' => $this->bookings[$uid]], 200);
    }

    /**
     * Die Antwort, die der Server unter cal.com fuer eine Route bildet, die er
     * unter dieser Version nicht kennt. Der 404, der sich liest wie "gibt es
     * nicht" und in Wahrheit "falsche Kopfzeile" heisst.
     */
    protected function routeMissing(string $method, string $path): Response|PromiseInterface
    {
        return Http::response($this->error('NotFoundException', "Cannot {$method} {$path}"), 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(string $code, string $message): array
    {
        return [
            'status' => 'error',
            'timestamp' => '2026-08-29T02:00:00.000Z',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => ['message' => $message, 'error' => $code, 'statusCode' => 400],
            ],
        ];
    }
}
