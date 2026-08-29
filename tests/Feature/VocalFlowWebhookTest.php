<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowSignature;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Goldnead\StatamicAutomations\Tests\Fixtures\RecordingTriggerDispatcher;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Die beiden Tueren, durch die VocalFlow hereinkommt, und alles, was sie nicht
 * hereinlassen.
 *
 * Diese Datei haelt die Sicherheitseigenschaften der zwei Routen fest. Sie sind
 * der Grund, warum der Anschluss eigene Routen mitbringen darf: eine offene
 * POST-Adresse, die Ablaeufe startet, ist ohne diese Zusagen ein Formular fuer
 * Fremde.
 *
 * Gemessen wird nicht am Statuscode allein. Ein Controller, der erst startet
 * und danach 403 antwortet, haette einen Statuscode-Test bestanden; deshalb
 * steht vor dem Dispatcher ein Mitschreiber, und die eigentliche Zusage lautet
 * "es wurde nichts gestartet".
 *
 * ## Der Unterschied zu cal.com, und warum er hier gemessen werden muss
 *
 * cal.com signiert die rohen Bytes, VocalFlow eine kanonisch neu kodierte
 * Fassung. Die Tests bauen deshalb die Anfrage genau so, wie VocalFlow sie
 * wirklich baut: den Rumpf mit PHPs **Vorgabe-Flags** (Schraegstriche und
 * Nicht-ASCII escapt, das macht Laravels HTTP-Client), die Signatur ueber die
 * kanonische Fassung.
 *
 * Ein Test, der beide aus derselben Zeichenkette bildet, wuerde nichts von dem
 * pruefen, was diesen Anschluss ausmacht — deswegen steht ganz oben ein Test,
 * der beweist, dass die beiden Fassungen bei den benutzten Nutzlasten
 * tatsaechlich auseinandergehen.
 */
class VocalFlowWebhookTest extends TestCase
{
    private const SECRET = 'ein-webhook-secret';

    private const PUBLICATION_SECRET = 'ein-publikations-token';

    private const URL = '/!/automations/vocalflow';

    private const PUBLISHED_URL = '/!/automations/vocalflow/session-published';

    private RecordingTriggerDispatcher $dispatcher;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('automations.integrations.vocalflow.secret', self::SECRET);
        $app['config']->set('automations.integrations.vocalflow.publication_secret', self::PUBLICATION_SECRET);
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

    // --- die Voraussetzung, gegen die alles Weitere prueft -------------------

    public function test_the_wire_bytes_and_the_signed_bytes_really_do_differ(): void
    {
        // Ohne diesen Unterschied waere jeder Test darunter zahnlos: er
        // pruefte, dass eine Signatur ueber X eine Nutzlast X akzeptiert, und
        // das gilt fuer jede Umsetzung. Der Unterschied entsteht am
        // Schraegstrich und am Umlaut, und beides steht in der Fixture, weil es
        // in echtem deutschem Unterrichtsinhalt steht.
        $payload = $this->fixture('task-assigned');

        $wire = $this->wire($payload);
        $canonical = VocalFlowSignature::canonical(json_decode($wire, false));

        $this->assertNotSame($wire, $canonical, 'Die benutzte Nutzlast enthaelt weder Schraegstrich noch Nicht-ASCII, damit prueft diese Datei nichts.');
        $this->assertStringContainsString('\/', $wire);
        $this->assertStringNotContainsString('\/', (string) $canonical);
        $this->assertEquals(json_decode($wire, true), json_decode((string) $canonical, true));
    }

    // --- was nicht hereinkommt ----------------------------------------------

    public function test_a_wrongly_signed_payload_is_rejected_and_starts_nothing(): void
    {
        $this->send($this->wire($this->body('session.created')), 'sha256='.str_repeat('a', 64))
            ->assertStatus(403)
            ->assertJson(['status' => 'invalid_signature']);

        $this->assertNothingStarted();
    }

    public function test_a_payload_without_a_signature_is_rejected(): void
    {
        $this->send($this->wire($this->body('session.created')), null)->assertStatus(403);

        $this->assertNothingStarted();
    }

    /**
     * Der Kern des Unterschieds zu cal.com, von der anderen Seite gemessen.
     *
     * Wer die **rohen Bytes** signiert, wie es bei cal.com richtig waere, kommt
     * hier nicht durch. Das ist keine Spitzfindigkeit: es ist der Beleg, dass
     * dieser Empfaenger tatsaechlich kanonisiert und nicht zufaellig
     * funktioniert, weil beide Fassungen bei einer harmlosen Testnutzlast
     * gleich aussehen.
     */
    public function test_a_signature_over_the_raw_wire_bytes_is_rejected(): void
    {
        $wire = $this->wire($this->body('session.created'));

        $this->send($wire, 'sha256='.hash_hmac('sha256', $wire, self::SECRET))->assertStatus(403);

        $this->assertNothingStarted();
    }

    /**
     * Die Kanonisierung wirft die Schreibweise weg, nicht die Bedeutung.
     *
     * Die Reihenfolge der Schluessel traegt Bedeutung — `json_decode` haelt sie
     * ein und `json_encode` gibt sie unveraendert wieder aus. Eine umsortierte
     * Nutzlast muss deshalb weiterhin scheitern, sonst waere aus der
     * Kanonisierung eine Lockerung geworden.
     */
    public function test_reordering_the_keys_still_breaks_the_signature(): void
    {
        $signed = ['event' => 'session.created', 'timestamp' => '2026-08-29T12:00:00Z'];
        $sent = ['timestamp' => '2026-08-29T12:00:00Z', 'event' => 'session.created'];

        $this->assertEquals($signed, array_intersect_key($sent, $signed));

        $this->send($this->wire($sent), $this->signatureFor($signed))->assertStatus(403);

        $this->assertNothingStarted();
    }

    public function test_the_signature_prefix_is_part_of_the_comparison(): void
    {
        // Ohne Praefix, und mit einem falschen. Ein Empfaenger, der das
        // Praefix abschneidet und nur den Rest prueft, nimmt beides an — und
        // merkt einen Verfahrenswechsel auf der Gegenseite nie.
        $payload = $this->body('session.created');
        $signature = $this->signatureFor($payload);
        $hex = substr($signature, strlen(VocalFlowSignature::PREFIX));

        $this->send($this->wire($payload), $hex)->assertStatus(403);
        $this->send($this->wire($payload), 'md5='.$hex)->assertStatus(403);

        $this->assertNothingStarted();
    }

    public function test_without_a_secret_the_route_accepts_nothing(): void
    {
        config()->set('automations.integrations.vocalflow.secret', null);

        $payload = $this->body('session.created');

        // Selbst eine Signatur, die zu einem leeren Secret passt, kommt nicht
        // durch. Ohne Zugangsdaten ist die Route zu, nicht offen.
        $canonical = (string) VocalFlowSignature::canonical(json_decode($this->wire($payload), false));

        $this->send($this->wire($payload), VocalFlowSignature::sign('', $canonical))
            ->assertStatus(503)
            ->assertJson(['status' => 'not_configured']);

        $this->assertNothingStarted();
    }

    public function test_a_body_that_is_not_json_is_rejected(): void
    {
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], 'nicht json')
            ->assertStatus(400)
            ->assertJson(['status' => 'malformed']);

        $this->assertNothingStarted();
    }

    public function test_a_body_without_an_event_is_rejected(): void
    {
        $payload = ['timestamp' => '2026-08-29T12:00:00Z', 'data' => []];

        $this->send($this->wire($payload), $this->signatureFor($payload))
            ->assertStatus(400)
            ->assertJson(['status' => 'no_event']);

        $this->assertNothingStarted();
    }

    public function test_an_oversized_body_is_turned_away_before_it_is_decoded(): void
    {
        config()->set('automations.integrations.vocalflow.max_body_bytes', 200);

        $payload = $this->body('session.created');
        $payload['data']['session']['title'] = str_repeat('x', 400);

        $this->send($this->wire($payload), $this->signatureFor($payload))->assertStatus(413);

        $this->assertNothingStarted();
    }

    /**
     * VocalFlow legt zwar ein `X-Webhook-Timestamp` in die Kopfzeilen, das ist
     * aber nicht mitsigniert und als Schranke deshalb wertlos: wer einen
     * mitgeschnittenen Rumpf hat, setzt den Header frisch. Der `timestamp` im
     * Rumpf ist mitsigniert und schliesst das.
     */
    public function test_an_old_envelope_is_rejected_even_with_a_valid_signature(): void
    {
        $payload = $this->body('session.created', '2026-08-01T09:00:00.000000Z');

        $this->send($this->wire($payload), $this->signatureFor($payload))
            ->assertStatus(400)
            ->assertJson(['status' => 'stale']);

        $this->assertNothingStarted();
    }

    public function test_a_fresh_header_does_not_rescue_an_old_envelope(): void
    {
        $payload = $this->body('session.created', '2026-08-01T09:00:00.000000Z');

        $this->send($this->wire($payload), $this->signatureFor($payload), [
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) now()->timestamp,
        ])->assertStatus(400);

        $this->assertNothingStarted();
    }

    public function test_an_unknown_event_is_accepted_and_ignored(): void
    {
        $payload = $this->body('session.updated');

        $this->send($this->wire($payload), $this->signatureFor($payload))
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

        $payload = $this->body('session.created');

        $this->send($this->wire($payload), $this->signatureFor($payload))
            ->assertStatus(202)
            ->assertJson(['status' => 'ignored']);

        $this->assertNothingStarted();
    }

    // --- was hereinkommt ----------------------------------------------------

    public function test_a_correctly_signed_payload_starts_exactly_one_flow(): void
    {
        $payload = $this->fixture('session-created');

        $this->send($this->wire($payload), $this->signatureFor($payload))
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertSame('vocalflow.session_created', $this->dispatcher->calls[0][0]);
        $this->assertSame(
            '019bffde-1a2b-7000-8000-000000000001',
            $this->dispatcher->calls[0][1]['data']['session']['id']
        );
    }

    public function test_every_event_in_the_map_reaches_its_own_trigger(): void
    {
        foreach ([
            'session-created' => 'vocalflow.session_created',
            'session-completed' => 'vocalflow.session_completed',
            'task-created' => 'vocalflow.task_created',
            'task-updated' => 'vocalflow.task_updated',
            'task-assigned' => 'vocalflow.task_assigned',
            'task-deleted' => 'vocalflow.task_deleted',
        ] as $fixture => $handle) {
            $payload = $this->fixture($fixture);

            $this->send($this->wire($payload), $this->signatureFor($payload))
                ->assertStatus(200)
                ->assertJson(['status' => 'ok']);
        }

        $this->assertSame([
            'vocalflow.session_created',
            'vocalflow.session_completed',
            'vocalflow.task_created',
            'vocalflow.task_updated',
            'vocalflow.task_assigned',
            'vocalflow.task_deleted',
        ], array_column($this->dispatcher->calls, 0));
    }

    // --- die Schranke gegen Doppelzustellung --------------------------------

    public function test_the_same_delivery_twice_reaches_the_engine_once(): void
    {
        $payload = $this->fixture('session-created');
        $wire = $this->wire($payload);
        $signature = $this->signatureFor($payload);

        $this->send($wire, $signature)->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->send($wire, $signature)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->dispatcher->calls);
    }

    /**
     * Der Grund, warum die Kennung nicht die des Vorgangs ist.
     *
     * Bei cal.com waere `(Ereignis, uid)` richtig, weil eine Buchung einmal
     * angelegt und einmal abgesagt wird. Bei VocalFlow feuert `task.updated`
     * jedesmal, wenn jemand an derselben Aufgabe etwas aendert. Wer auf
     * `(task.updated, task.id)` sperrt, verwirft die zweite echte Aenderung —
     * und der Ablauf, der auf "Aufgabe ist jetzt fertig" wartet, laeuft nie.
     */
    public function test_a_second_real_change_to_the_same_task_is_not_swallowed(): void
    {
        $first = $this->fixture('task-updated');

        $second = $first;
        $second['timestamp'] = '2026-08-29T12:50:00.000000Z';
        $second['data']['task']['status'] = 'cancelled';
        $second['data']['task']['updated_at'] = '2026-08-29T12:49:58.000000Z';
        $second['data']['changes'] = ['status' => ['from' => 'completed', 'to' => 'cancelled']];

        $this->send($this->wire($first), $this->signatureFor($first))->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->send($this->wire($second), $this->signatureFor($second))->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls, 'Die zweite echte Aenderung an derselben Aufgabe wurde als Wiederholung verworfen.');
    }

    /**
     * Die Kennung darf nicht am ungesignierten Header haengen.
     *
     * VocalFlow schickt ein `X-Webhook-ID`, es steht aber nicht in der
     * signierten Nutzlast. Wer darauf sperrt, sperrt auf einen Wert, den ein
     * Fremder mit einem mitgeschnittenen Rumpf frei setzen kann — und kaeme
     * damit an der Schranke vorbei.
     */
    public function test_a_changed_delivery_header_does_not_get_past_the_barrier(): void
    {
        $payload = $this->fixture('session-created');
        $wire = $this->wire($payload);
        $signature = $this->signatureFor($payload);

        $this->send($wire, $signature, ['HTTP_X_WEBHOOK_ID' => 'erste'])->assertStatus(200);
        $this->send($wire, $signature, ['HTTP_X_WEBHOOK_ID' => 'zweite'])->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->dispatcher->calls);
    }

    /**
     * Wenn der Start scheitert, muss die Vormerkung zurueck.
     *
     * Sonst waere das Ereignis verloren: die Vormerkung stuende, VocalFlows
     * Wiederholung liefe in "schon dagewesen", und der Ablauf startete nie.
     */
    public function test_a_failed_start_does_not_swallow_the_redelivery(): void
    {
        $payload = $this->fixture('session-created');
        $wire = $this->wire($payload);
        $signature = $this->signatureFor($payload);

        $this->dispatcher->failWith = new RuntimeException('Die Queue ist gerade weg');
        $this->send($wire, $signature)->assertStatus(500);

        $this->dispatcher->failWith = null;
        $this->send($wire, $signature)->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls);
    }

    /**
     * Ein Cache, der nichts behaelt, darf nicht alles verwerfen.
     *
     * Beim `null`-Treiber antwortet `Cache::add` immer `false`. Wer das
     * ungeprueft als "schon dagewesen" liest, verwirft jede Zustellung,
     * antwortet dabei mit 200 und startet nie einen Ablauf: der Anschluss
     * meldet Erfolg und tut nichts. Das ist die zaeheste Fehlerform, weil sie
     * von aussen wie Betrieb aussieht.
     */
    public function test_a_cache_that_remembers_nothing_lets_the_event_through(): void
    {
        config()->set('cache.default', 'null');

        $payload = $this->fixture('session-created');
        $wire = $this->wire($payload);
        $signature = $this->signatureFor($payload);

        $this->send($wire, $signature)->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->send($wire, $signature)->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls);
    }

    // --- die zweite Tuer: die veroeffentlichte Session -----------------------

    public function test_the_published_route_rejects_a_request_without_a_token(): void
    {
        $this->sendPublished($this->publishedBody(), null)
            ->assertStatus(401)
            ->assertJson(['status' => 'invalid_token']);

        $this->assertNothingStarted();
    }

    public function test_the_published_route_rejects_a_wrong_token(): void
    {
        $this->sendPublished($this->publishedBody(), 'nicht-das-token')->assertStatus(401);

        $this->assertNothingStarted();
    }

    public function test_the_published_route_does_not_accept_the_event_secret(): void
    {
        // Zwei Abos, zwei Geheimnisse. Wer sie verwechselt, soll das erfahren
        // und nicht zufaellig durchkommen.
        $this->sendPublished($this->publishedBody(), self::SECRET)->assertStatus(401);

        $this->assertNothingStarted();
    }

    public function test_without_a_publication_secret_the_published_route_accepts_nothing(): void
    {
        config()->set('automations.integrations.vocalflow.publication_secret', null);

        $this->sendPublished($this->publishedBody(), self::PUBLICATION_SECRET)
            ->assertStatus(503)
            ->assertJson(['status' => 'not_configured']);

        $this->assertNothingStarted();
    }

    public function test_a_published_session_starts_exactly_one_flow(): void
    {
        $this->sendPublished($this->publishedBody(), self::PUBLICATION_SECRET)
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertSame('vocalflow.session_published', $this->dispatcher->calls[0][0]);
        $this->assertSame('nina@example.com', $this->dispatcher->calls[0][1]['data']['student']['email']);
    }

    public function test_the_same_published_session_twice_reaches_the_engine_once(): void
    {
        $body = $this->publishedBody();

        $this->sendPublished($body, self::PUBLICATION_SECRET)->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->sendPublished($body, self::PUBLICATION_SECRET)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->dispatcher->calls);
    }

    /**
     * Die Korrektur einer falschen Adresse darf die Schranke nicht fressen.
     *
     * Wer bemerkt, dass die Veroeffentlichung an die falsche Adresse ging, und
     * dieselbe Session mit der richtigen noch einmal schickt, bekaeme bei einer
     * Kennung aus der `session_id` allein 200 und nichts liefe — die Korrektur
     * waere still verschluckt, und zwar von der Schranke, die nur
     * Wiederholungen abfangen soll.
     */
    public function test_the_same_session_for_a_different_address_runs(): void
    {
        $this->sendPublished($this->publishedBody('nina@example.com'), self::PUBLICATION_SECRET)->assertStatus(200);
        $this->sendPublished($this->publishedBody('nina.soemmer@example.com'), self::PUBLICATION_SECRET)
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls);
    }

    public function test_the_published_route_refuses_a_payload_that_is_not_one(): void
    {
        foreach ([
            [],
            ['session_id' => ''],
            ['session_id' => 'abc'],
            ['student_email' => 'nina@example.com'],
            ['session_id' => 'abc', 'student_email' => ''],
            ['session_id' => 'abc', 'student_email' => 'keine adresse'],
            ['session_id' => ['abc'], 'student_email' => 'nina@example.com'],
            ['session_id' => 'abc', 'student_email' => ['nina@example.com']],
        ] as $payload) {
            $this->sendPublished(json_encode($payload, JSON_THROW_ON_ERROR), self::PUBLICATION_SECRET)
                ->assertStatus(400)
                ->assertJson(['status' => 'invalid_payload']);
        }

        $this->assertNothingStarted();
    }

    public function test_a_failed_start_on_the_published_route_does_not_swallow_the_redelivery(): void
    {
        $body = $this->publishedBody();

        $this->dispatcher->failWith = new RuntimeException('Die Queue ist gerade weg');
        $this->sendPublished($body, self::PUBLICATION_SECRET)->assertStatus(500);

        $this->dispatcher->failWith = null;
        $this->sendPublished($body, self::PUBLICATION_SECRET)->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertCount(2, $this->dispatcher->calls);
    }

    // --- die Routen selbst --------------------------------------------------

    public function test_both_routes_carry_no_session_and_no_csrf_middleware(): void
    {
        // Der Test, den die Statuscodes nicht leisten koennen: Laravel schaltet
        // den CSRF-Schutz unter `runningUnitTests()` ab, eine gruene Suite
        // beweist hier also gar nichts. Wer eine der Routen aus Ordnungsliebe
        // zurueck in Statamics `web`-Gruppe haengt, bekaeme im Betrieb 419 und
        // im Test nichts davon zu sehen. Deshalb werden die Routen befragt.
        foreach ([
            'automations.webhooks.vocalflow' => '!/automations/vocalflow',
            'automations.webhooks.vocalflow_session_published' => '!/automations/vocalflow/session-published',
        ] as $name => $uri) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Die Route {$name} ist gar nicht registriert.");
            $this->assertSame($uri, $route->uri());

            $middleware = $route->gatherMiddleware();

            foreach (['web', 'statamic.web'] as $group) {
                $this->assertNotContains($group, $middleware);
            }

            foreach ($middleware as $entry) {
                $this->assertStringNotContainsStringIgnoringCase('csrf', (string) $entry);
                $this->assertStringNotContainsStringIgnoringCase('RequestForgery', (string) $entry);
                $this->assertStringNotContainsStringIgnoringCase('StartSession', (string) $entry);
            }

            // Was stattdessen darauf liegt: die Bremse gegen jemanden, der die
            // URL kennt und sie ohne Zugangsdaten in Dauerschleife aufruft.
            $this->assertContains('throttle:120,1', $middleware);
        }
    }

    public function test_the_cal_com_route_still_carries_its_own_middleware(): void
    {
        // Die Drosselung haengt seit diesem Anschluss an der einzelnen Route
        // und nicht mehr an der Gruppe, damit ein zweiter Dienst nicht die
        // Einstellung des ersten erbt. Der Umbau darf den Nachbarn nicht
        // entwaffnet haben.
        $route = app('router')->getRoutes()->getByName('automations.webhooks.cal_com');

        $this->assertNotNull($route);
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    // --- Helfer -------------------------------------------------------------

    private function assertNothingStarted(): void
    {
        $this->assertSame([], $this->dispatcher->calls, 'Es wurde ein Ablauf gestartet, obwohl die Anfrage abgelehnt wurde.');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $event, string $timestamp = '2026-08-29T12:00:00.000000Z'): array
    {
        return [
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => [
                'session' => ['id' => '019bffde-1a2b-7000-8000-000000000001', 'status' => 'scheduled'],
                'student' => ['id' => 42, 'name' => 'Nina Sömmer', 'email' => 'nina@example.com'],
            ],
        ];
    }

    private function publishedBody(string $email = 'nina@example.com'): string
    {
        return json_encode([
            'session_id' => '019bffde-1a2b-7000-8000-000000000001',
            'student_email' => $email,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/vocalflow/'.$name.'.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Die Bytes, wie sie auf der Leitung ankommen.
     *
     * PHPs Vorgabe-Flags, weil VocalFlow die Nutzlast an Laravels HTTP-Client
     * uebergibt und der genau so kodiert: Schraegstriche zu `\/`, Umlaute zu
     * `\uXXXX`. Genau diese zweite Kodierung ist der Grund, warum ueber die
     * kanonische Fassung signiert werden muss.
     *
     * @param  array<string, mixed>  $payload
     */
    private function wire(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Die Signatur, die VocalFlow bilden wuerde.
     *
     * Ueber `VocalFlowSignature` und nicht selbst nachprogrammiert: eine
     * Testsuite, die ihre Signatur selbst baut, prueft am Ende ihre eigene
     * Kopie und nicht diesen Code.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signatureFor(array $payload): string
    {
        $canonical = VocalFlowSignature::canonical(json_decode($this->wire($payload), false));

        return VocalFlowSignature::sign(self::SECRET, (string) $canonical);
    }

    /**
     * Ein POST mit genau diesen Bytes im Rumpf.
     *
     * Ueber `call()` und nicht ueber `post()`, weil `post()` ein Array nimmt
     * und daraus selbst JSON baut. Genau das waere hier falsch: der Test muss
     * die Bytes selbst bestimmen, sonst kann er nicht pruefen, ob der
     * Controller aus abweichenden Bytes dieselbe kanonische Fassung gewinnt.
     *
     * @param  array<string, string>  $server
     */
    private function send(string $body, ?string $signature, array $server = []): TestResponse
    {
        $server += ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }

    private function sendPublished(string $body, ?string $token): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('POST', self::PUBLISHED_URL, [], [], [], $server, $body);
    }
}
