<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComSignature;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Goldnead\StatamicAutomations\Tests\Fixtures\RecordingTriggerDispatcher;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Die Tuer, durch die cal.com hereinkommt, und alles, was sie nicht
 * hereinlaesst.
 *
 * Diese Datei haelt die Sicherheitseigenschaften der Route fest. Sie sind der
 * Grund, warum der Anschluss seine eigene Route mitbringen darf: eine offene
 * POST-Adresse, die Ablaeufe startet, ist ohne diese Zusagen ein Formular fuer
 * Fremde.
 *
 * Gemessen wird nicht am Statuscode allein. Ein Controller, der erst startet
 * und danach 403 antwortet, haette einen Statuscode-Test bestanden; deshalb
 * steht vor dem Dispatcher ein Mitschreiber, und die eigentliche Zusage lautet
 * "es wurde nichts gestartet".
 */
class CalComWebhookTest extends TestCase
{
    private const SECRET = 'ein-webhook-secret';

    private const URL = '/!/automations/cal-com';

    private RecordingTriggerDispatcher $dispatcher;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('automations.integrations.cal_com.secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Die Uhr steht auf dem Tag der Fixtures. Sonst liefe die Altersgrenze
        // irgendwann gegen sie, und die Tests wuerden von selbst rot, ohne dass
        // sich am Code etwas geaendert haette.
        $this->travelTo(new \DateTimeImmutable('2026-08-29T13:00:00+00:00'));

        $this->dispatcher = new RecordingTriggerDispatcher;
        $this->app->instance(TriggerDispatcher::class, $this->dispatcher);
    }

    // --- was nicht hereinkommt ----------------------------------------------

    public function test_a_wrongly_signed_payload_is_rejected_and_starts_nothing(): void
    {
        $this->send($this->body('BOOKING_CREATED', 'uid-1'), str_repeat('a', 64))
            ->assertStatus(403)
            ->assertJson(['status' => 'invalid_signature']);

        $this->assertNothingStarted();
    }

    public function test_a_payload_without_a_signature_is_rejected(): void
    {
        $this->send($this->body('BOOKING_CREATED', 'uid-1'), null)->assertStatus(403);

        $this->assertNothingStarted();
    }

    /**
     * Ist bei cal.com kein Secret hinterlegt, fehlt der Header nicht: cal.com
     * schickt woertlich `no-secret-provided`. Ein Empfaenger, der nur prueft,
     * ob ein Header da ist, laesst das durch.
     */
    public function test_the_placeholder_cal_com_sends_without_a_secret_is_rejected(): void
    {
        $this->send($this->body('BOOKING_CREATED', 'uid-1'), 'no-secret-provided')->assertStatus(403);

        $this->assertNothingStarted();
    }

    public function test_without_a_secret_the_route_accepts_nothing(): void
    {
        config()->set('automations.integrations.cal_com.secret', null);

        $body = $this->body('BOOKING_CREATED', 'uid-1');

        // Selbst eine Signatur, die zu einem leeren Secret passt, kommt nicht
        // durch. Ohne Zugangsdaten ist die Route zu, nicht offen.
        $this->send($body, CalComSignature::sign('', $body))
            ->assertStatus(503)
            ->assertJson(['status' => 'not_configured']);

        $this->assertNothingStarted();
    }

    public function test_a_body_that_is_not_json_is_rejected(): void
    {
        $this->signed('nicht json')->assertStatus(400);

        $this->assertNothingStarted();
    }

    public function test_a_body_without_a_trigger_event_is_rejected(): void
    {
        $this->signed('{"payload":{"uid":"uid-1"}}')->assertStatus(400)->assertJson(['status' => 'no_trigger_event']);

        $this->assertNothingStarted();
    }

    public function test_an_oversized_body_is_turned_away_before_it_is_hashed(): void
    {
        config()->set('automations.integrations.cal_com.max_body_bytes', 200);

        $this->signed(json_encode([
            'triggerEvent' => 'BOOKING_CREATED',
            'createdAt' => '2026-08-29T12:00:00.000Z',
            'payload' => ['uid' => 'uid-1', 'title' => str_repeat('x', 400)],
        ], JSON_THROW_ON_ERROR))->assertStatus(413);

        $this->assertNothingStarted();
    }

    /**
     * cal.com legt keine Zustell-Kennung und keinen Zeitstempel in die
     * Kopfzeilen. Ein einmal mitgeschnittener, gueltig signierter Rumpf bliebe
     * damit fuer immer gueltig: wer ihn aus einem Protokoll hat, koennte den
     * Ablauf spaeter beliebig oft ausloesen. Der Zeitstempel im Rumpf ist
     * mitsigniert und schliesst das.
     */
    public function test_an_old_envelope_is_rejected_even_with_a_valid_signature(): void
    {
        $this->signed($this->body('BOOKING_CREATED', 'uid-1', '2026-08-01T09:00:00.000Z'))
            ->assertStatus(400)
            ->assertJson(['status' => 'stale']);

        $this->assertNothingStarted();
    }

    public function test_an_unknown_trigger_event_is_accepted_and_ignored(): void
    {
        $this->signed($this->body('MEETING_ENDED', 'uid-1'))
            ->assertStatus(202)
            ->assertJson(['status' => 'ignored']);

        $this->assertNothingStarted();
    }

    public function test_a_trigger_switched_off_in_the_config_is_ignored_rather_than_reported_as_done(): void
    {
        // Wer einen Auslöser ueber `builtin_nodes` abschaltet, hat die Route
        // weiterhin offen. "ok" zu antworten hiesse Erfolg zu melden fuer
        // nichts getan.
        $this->app->instance(TriggerRegistry::class, new TriggerRegistry);

        $this->signed($this->body('BOOKING_CREATED', 'uid-1'))
            ->assertStatus(202)
            ->assertJson(['status' => 'ignored']);
    }

    /**
     * Die Signatur deckt den rohen Rumpf ab, nicht das dekodierte Array.
     *
     * Der Beleg dafuer ist ein Rumpf, der als JSON identisch ist und als Bytes
     * nicht: dieselben Schluessel in anderer Reihenfolge. Wer ueber das
     * dekodierte und neu kodierte Array signiert, laesst das durch.
     */
    public function test_the_signature_covers_the_raw_bytes_and_not_the_decoded_array(): void
    {
        $signed = '{"triggerEvent":"BOOKING_CREATED","payload":{"uid":"uid-1"}}';
        $sent = '{"payload":{"uid":"uid-1"},"triggerEvent":"BOOKING_CREATED"}';

        $this->assertEquals(json_decode($signed, true), json_decode($sent, true));

        $this->send($sent, CalComSignature::sign(self::SECRET, $signed))->assertStatus(403);

        $this->assertNothingStarted();
    }

    // --- was hereinkommt ----------------------------------------------------

    public function test_a_correctly_signed_payload_starts_exactly_one_flow(): void
    {
        $this->signed($this->body('BOOKING_CREATED', 'uid-1'))
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertSame('cal_com.booking_created', $this->dispatcher->calls[0][0]);
        $this->assertSame('uid-1', $this->dispatcher->calls[0][1]['payload']['uid']);
    }

    // --- die Schranke gegen Doppelzustellung --------------------------------

    public function test_the_same_delivery_twice_reaches_the_engine_once(): void
    {
        $body = $this->body('BOOKING_CREATED', 'uid-1');

        $this->signed($body)->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->signed($body)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->dispatcher->calls);
    }

    public function test_a_delivery_without_a_uid_falls_back_to_the_body_fingerprint(): void
    {
        // Eine Nutzlast-Vorlage im cal.com-Konto kann die `uid` weglassen. Eine
        // Wiederholung schickt dieselben Bytes, also traegt der Fingerabdruck
        // genau so weit.
        $body = '{"triggerEvent":"BOOKING_CREATED","createdAt":"2026-08-29T12:00:00.000Z","payload":{"title":"ohne uid"}}';

        $this->signed($body)->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->signed($body)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->dispatcher->calls);
    }

    /**
     * Wenn der Start scheitert, muss die Vormerkung zurueck.
     *
     * Sonst waere die Buchung verloren: die Vormerkung stuende, cal.coms
     * Wiederholung liefe in "schon dagewesen", und der Ablauf startete nie. Die
     * Schranke wuerde damit genau den Mechanismus entwaffnen, dessentwegen es
     * sie gibt.
     */
    public function test_a_failed_start_does_not_swallow_the_redelivery(): void
    {
        $body = $this->body('BOOKING_CREATED', 'uid-1');

        $this->dispatcher->failWith = new RuntimeException('Die Queue ist gerade weg');
        $this->signed($body)->assertStatus(500);

        $this->dispatcher->failWith = null;
        $this->signed($body)->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls);
    }

    /**
     * Ein Cache, der nichts behaelt, darf nicht alles verwerfen.
     *
     * Beim `null`-Treiber antwortet `Cache::add` immer `false`. Wer das
     * ungeprueft als "schon dagewesen" liest, verwirft jede Zustellung,
     * antwortet cal.com dabei mit 200 und startet nie einen Ablauf: der
     * Anschluss meldet Erfolg und tut nichts. Das ist die zaeheste Fehlerform,
     * weil sie von aussen wie Betrieb aussieht.
     */
    public function test_a_cache_that_remembers_nothing_lets_the_event_through(): void
    {
        config()->set('cache.default', 'null');

        $this->signed($this->body('BOOKING_CREATED', 'uid-1'))->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->signed($this->body('BOOKING_CREATED', 'uid-1'))->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls);
    }

    // --- die Route selbst ---------------------------------------------------

    public function test_the_route_carries_no_session_and_no_csrf_middleware(): void
    {
        // Der Test, den die Statuscodes nicht leisten koennen: Laravel schaltet
        // den CSRF-Schutz unter `runningUnitTests()` ab, eine gruene Suite
        // beweist hier also gar nichts. Wer die Route aus Ordnungsliebe zurueck
        // in Statamics `web`-Gruppe haengt, bekaeme im Betrieb 419 und im Test
        // nichts davon zu sehen. Deshalb wird die Route selbst befragt.
        $route = app('router')->getRoutes()->getByName('automations.webhooks.cal_com');

        $this->assertNotNull($route, 'Die Route ist gar nicht registriert.');
        $this->assertSame('!/automations/cal-com', $route->uri());

        $middleware = $route->gatherMiddleware();

        foreach (['web', 'statamic.web'] as $group) {
            $this->assertNotContains($group, $middleware);
        }

        foreach ($middleware as $entry) {
            $this->assertStringNotContainsStringIgnoringCase('csrf', (string) $entry);
            $this->assertStringNotContainsStringIgnoringCase('RequestForgery', (string) $entry);
            $this->assertStringNotContainsStringIgnoringCase('StartSession', (string) $entry);
        }

        // Was stattdessen darauf liegt: die Bremse gegen jemanden, der die URL
        // kennt und sie ohne Secret in Dauerschleife aufruft.
        $this->assertContains('throttle:120,1', $middleware);
    }

    // --- Helfer -------------------------------------------------------------

    private function assertNothingStarted(): void
    {
        $this->assertSame([], $this->dispatcher->calls, 'Es wurde ein Ablauf gestartet, obwohl die Anfrage abgelehnt wurde.');
    }

    private function body(string $triggerEvent, string $uid, string $createdAt = '2026-08-29T12:00:00.000Z'): string
    {
        return json_encode([
            'triggerEvent' => $triggerEvent,
            'createdAt' => $createdAt,
            'payload' => ['uid' => $uid, 'title' => 'Kennenlernen'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Eine Anfrage mit gueltiger Signatur.
     */
    private function signed(string $body): TestResponse
    {
        return $this->send($body, CalComSignature::sign(self::SECRET, $body));
    }

    /**
     * Ein POST mit genau diesen Bytes im Rumpf.
     *
     * Ueber `call()` und nicht ueber `post()`, weil `post()` ein Array nimmt
     * und daraus selbst JSON baut. Genau das waere hier falsch: die Signatur
     * deckt Bytes ab, und ein Test, der die Bytes nicht selbst bestimmt, kann
     * nicht pruefen, ob der Controller die richtigen signiert.
     */
    private function send(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_CAL_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }
}
