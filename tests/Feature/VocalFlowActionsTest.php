<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Actions\CreateStudentAction;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Actions\GrantPackageAction;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Die Gegenrichtung: die zwei Aktionen, die zu VocalFlow hinausrufen.
 *
 * Es sind genau zwei, und das ist eine Entscheidung: die Partner-API kann
 * Sessions listen und absagen, Aufgaben abschliessen, Kommentare schreiben und
 * mehr. Gebaut sind die beiden Schritte des Onboardings, die es wirklich gibt.
 * Der Rest waere Vorrat, den heute nichts ruft, und ein ungenutzter Knoten
 * steht trotzdem im Editor und will bei jeder Aenderung mitgetestet werden.
 *
 * Was diese Datei festhaelt, sind drei Eigenschaften, an denen ein Anschluss
 * nach aussen scheitert:
 *
 *   - **Ohne Zugangsdaten passiert nichts.** Kein Aufruf ins Leere, keine
 *     Fehlermeldung ueber einen Verbindungsfehler, wo "hier ist nichts
 *     eingerichtet" gemeint ist.
 *   - **Ein Fehlschlag zerreisst den Ablauf nicht.** Er kommt als Ergebnis
 *     zurueck und nicht als Ausnahme, die durch die Ablauf-Maschine faellt, und
 *     die Meldung sagt, was schiefging.
 *   - **Ein Testlauf schickt nichts.** Einen Studenten anzulegen und ein Paket
 *     gutzuschreiben sind echte, sichtbare, von hier aus nicht
 *     zurueckzunehmende Vorgaenge in einem fremden System.
 */
class VocalFlowActionsTest extends TestCase
{
    private const BASE = 'https://vocalflow.test';

    private const SECRET = 'ein-partner-secret';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('automations.integrations.vocalflow.partner_url', self::BASE);
        $app['config']->set('automations.integrations.vocalflow.partner_secret', self::SECRET);
    }

    // --- ohne Zugangsdaten passiert nichts ----------------------------------

    public function test_without_credentials_the_actions_do_nothing_and_say_so(): void
    {
        Http::fake();

        foreach ([
            ['partner_url' => null, 'partner_secret' => self::SECRET],
            ['partner_url' => self::BASE, 'partner_secret' => null],
            ['partner_url' => '', 'partner_secret' => ''],
        ] as $settings) {
            config()->set('automations.integrations.vocalflow.partner_url', $settings['partner_url']);
            config()->set('automations.integrations.vocalflow.partner_secret', $settings['partner_secret']);

            $result = app(CreateStudentAction::class)->execute(
                AutomationContext::make(),
                ['email' => 'nina@example.com', 'name' => 'Nina Sömmer'],
            );

            $this->assertTrue($result->isFailed());
            $this->assertStringContainsString('not configured', (string) $result->error);

            $package = app(GrantPackageAction::class)->execute(
                AutomationContext::make(),
                $this->packageConfig(),
            );

            $this->assertTrue($package->isFailed());
            $this->assertStringContainsString('not configured', (string) $package->error);
        }

        // Und ausdruecklich: es wurde nicht ins Leere gerufen.
        Http::assertNothingSent();
    }

    // --- der Weg hinaus -----------------------------------------------------

    public function test_creating_a_student_hits_the_partner_api_with_a_bearer_token(): void
    {
        Http::fake([
            self::BASE.'/api/partner/v1/students' => Http::response([
                'data' => [
                    'id' => 42,
                    'uuid' => '019bffde-57ad-7000-8000-00000000000b',
                    'email' => 'nina@example.com',
                    'name' => 'Nina Sömmer',
                    'created' => true,
                    'coach_assigned' => true,
                ],
            ], 200),
        ]);

        $result = app(CreateStudentAction::class)->execute(
            AutomationContext::make(),
            ['email' => 'Nina@Example.com', 'name' => '  Nina Sömmer  '],
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->output['id']);
        $this->assertSame('019bffde-57ad-7000-8000-00000000000b', $result->output['uuid']);
        $this->assertTrue($result->output['created']);
        $this->assertTrue($result->output['coach_assigned']);

        Http::assertSent(function (Request $request) {
            $this->assertSame(self::BASE.'/api/partner/v1/students', $request->url());
            $this->assertSame('Bearer '.self::SECRET, $request->header('Authorization')[0]);

            // Kleingeschrieben und getrimmt hinaus: VocalFlow sucht ohne
            // Ruecksicht auf Gross- und Kleinschreibung, speichert die Adresse
            // aber so, wie sie ankommt. Zwei Schreibweisen in zwei Protokollen
            // sucht man spaeter vergeblich.
            $this->assertSame('nina@example.com', $request->data()['email']);
            $this->assertSame('Nina Sömmer', $request->data()['name']);

            return true;
        });
    }

    public function test_a_one_time_package_carries_its_expiry_and_no_monthly_credit(): void
    {
        // Beide Zusatzfelder im Knoten gesetzt, obwohl nur eines zu dieser
        // Paketart gehoert. VocalFlow wuerde das andere still ignorieren — und
        // im Ablaufprotokoll stuende dann ein Feld, das nie gewirkt hat, und der
        // naechste, der einen Fehler sucht, glaubt es.
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::response(['data' => ['id' => 'kauf-1', 'created' => true]], 200),
        ]);

        app(GrantPackageAction::class)->execute(AutomationContext::make(), $this->packageConfig([
            'package_type' => 'one_time',
            'expires_months' => 4,
            'monthly_credits' => 9,
        ]));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            $this->assertSame(4, $data['expires_months']);
            $this->assertArrayNotHasKey('monthly_credits', $data, 'Ein Kontingent hat keine monatliche Gutschrift.');

            return true;
        });
    }

    public function test_a_subscription_carries_its_monthly_credit_and_never_expires(): void
    {
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::response(['data' => ['id' => 'kauf-2', 'created' => true]], 200),
        ]);

        app(GrantPackageAction::class)->execute(AutomationContext::make(), $this->packageConfig([
            'package_type' => 'subscription',
            'total_sessions' => 0,
            'expires_months' => 4,
            'monthly_credits' => 1,
        ]));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            $this->assertSame(1, $data['monthly_credits']);
            $this->assertArrayNotHasKey('expires_months', $data, 'Ein Abonnement laeuft nicht ab.');

            return true;
        });
    }

    public function test_an_address_with_a_plus_survives_the_url(): void
    {
        // Ein `+` ist in einer Adresse zulaessig und in einem URL-Pfad etwas
        // anderes als ein Leerzeichen. Ohne Kodierung sucht VocalFlow nach
        // "nina chor@example.com" und antwortet 404 auf einen Studenten, den es
        // gibt.
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::response(['data' => ['id' => 'kauf-1', 'created' => true]], 200),
        ]);

        app(GrantPackageAction::class)->execute(
            AutomationContext::make(),
            ['email' => 'nina+chor@example.com'] + $this->packageConfig(),
        );

        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('nina%2Bchor%40example.com', $request->url());

            return true;
        });
    }

    public function test_without_an_idempotency_key_none_is_sent(): void
    {
        // Die Vorgabe ist bewusst "kein Schluessel". Einen aus der Nutzlast
        // abzuleiten waere schlimmer als keiner: er waere fuer denselben
        // Studenten mit demselben Paket immer derselbe und verschluckte damit
        // den zweiten echten Kauf desselben Pakets, was ein voellig normaler
        // Vorgang ist.
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::response(['data' => ['id' => 'kauf-1', 'created' => true]], 200),
        ]);

        app(GrantPackageAction::class)->execute(AutomationContext::make(), $this->packageConfig());

        Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key') === []);
    }

    public function test_an_idempotency_key_travels_and_a_repeat_reports_that_nothing_was_created(): void
    {
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::response(['data' => ['id' => 'kauf-1', 'created' => false]], 200),
        ]);

        $result = app(GrantPackageAction::class)->execute(
            AutomationContext::make(),
            $this->packageConfig(['idempotency_key' => '  bestellung-4711  ']),
        );

        Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key') === ['bestellung-4711']);

        // VocalFlow meldet beim zweiten Mal denselben Kauf und `created` gleich
        // `false`. Das ist Erfolg und kein Fehler, aber ein Ablauf soll darauf
        // verzweigen koennen.
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['created']);
        $this->assertSame('kauf-1', $result->output['id']);
    }

    // --- ein Fehlschlag zerreisst den Ablauf nicht --------------------------

    public function test_a_rejected_request_comes_back_as_a_readable_failure(): void
    {
        // Als Folge und nicht als vier einzelne Attrappen: `Http::fake()` legt
        // Stubs zusammen statt sie zu ersetzen, vier Aufrufe bekaemen sonst
        // alle die erste Antwort und der Test bestaetigte nur sich selbst.
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::sequence()
                ->push(['error' => 'STUDENT_NOT_FOUND'], 404)
                ->push(['error' => 'UNAUTHORIZED'], 401)
                ->push(['message' => 'The given data was invalid.', 'errors' => ['session_type_id' => ['The selected session type id is invalid.']]], 422)
                ->push([], 500),
        ]);

        foreach ([
            'VocalFlow rejected the request (404): STUDENT_NOT_FOUND',
            'VocalFlow rejected the request (401): UNAUTHORIZED',
            'VocalFlow rejected the request (422): session_type_id: The selected session type id is invalid.',
            'VocalFlow rejected the request (500).',
        ] as $expected) {
            $result = app(GrantPackageAction::class)->execute(
                AutomationContext::make(),
                $this->packageConfig(),
            );

            $this->assertTrue($result->isFailed(), "Erwartet: {$expected}");
            $this->assertSame($expected, $result->error);
        }
    }

    public function test_a_server_that_is_not_reachable_comes_back_as_a_failure_and_not_as_an_exception(): void
    {
        // Der Fall, an dem eine Aktion einen ganzen Ablauf mitreissen kann:
        // eine Ausnahme aus dem HTTP-Client, die niemand faengt.
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = app(CreateStudentAction::class)->execute(
            AutomationContext::make(),
            ['email' => 'nina@example.com', 'name' => 'Nina Sömmer'],
        );

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('could not be reached', (string) $result->error);
        $this->assertStringContainsString('timed out', (string) $result->error);
    }

    /**
     * Und der ganze Weg: der Knoten geht rot, die Maschine bleibt heil.
     *
     * Gemessen ueber den `NodeExecutor` und nicht ueber die Aktion allein, weil
     * genau dort der Unterschied liegt: eine Aktion, die wirft statt ein
     * Ergebnis zurueckzugeben, macht aus einem roten Knoten einen abgebrochenen
     * Lauf, und der Rest des Ablaufs — die Benachrichtigung, der Eintrag im
     * CRM — passiert dann nicht mehr.
     */
    public function test_a_failing_action_leaves_the_engine_in_control(): void
    {
        Http::fake([
            self::BASE.'/api/partner/v1/*' => Http::response(['error' => 'STUDENT_NOT_FOUND'], 404),
        ]);

        $node = new AutomationNode([
            'node_key' => 'paket',
            'type' => 'vocalflow.grant_package',
            'config' => ['email' => 'nina@example.com'] + $this->packageConfig(),
        ]);

        $result = app(NodeExecutor::class)->execute($node, AutomationContext::make());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('STUDENT_NOT_FOUND', (string) $result->error);

        // Kein durchgereichter Ausnahme-Rumpf: die Fehlermeldung ist die der
        // Aktion und nicht die eines gefangenen Throwables.
        $this->assertArrayNotHasKey('exception', $result->output);
    }

    // --- ein Testlauf schickt nichts ----------------------------------------

    public function test_a_test_run_previews_and_sends_nothing(): void
    {
        Http::fake();

        $student = app(CreateStudentAction::class)->execute(
            AutomationContext::make([], testMode: true),
            ['email' => 'nina@example.com', 'name' => 'Nina Sömmer'],
        );

        $this->assertTrue($student->isSuccess());
        $this->assertSame('nina@example.com', $student->output['preview']['email']);

        $package = app(GrantPackageAction::class)->execute(
            AutomationContext::make([], testMode: true),
            ['email' => 'nina@example.com'] + $this->packageConfig(),
        );

        $this->assertTrue($package->isSuccess());
        $this->assertSame(3, $package->output['preview']['total_sessions']);

        Http::assertNothingSent();
    }

    public function test_a_test_run_still_goes_red_on_a_misconfigured_node(): void
    {
        // Die Grenze aus ActionResult::missingDataReference(): statische
        // Konfiguration wird vor dem Testmodus geprueft, Datenreferenzen
        // danach. Ein Knoten ohne Namen ist falsch eingerichtet, und dafuer ist
        // ein Testlauf da.
        Http::fake();

        $this->assertTrue(
            app(CreateStudentAction::class)->execute(
                AutomationContext::make([], testMode: true),
                ['email' => 'nina@example.com', 'name' => ''],
            )->isFailed()
        );

        foreach ([
            ['session_type_id' => ''],
            ['package_type' => 'geschenk'],
            ['total_sessions' => -1],
            ['total_sessions' => 'drei'],
        ] as $broken) {
            $this->assertTrue(
                app(GrantPackageAction::class)->execute(
                    AutomationContext::make([], testMode: true),
                    $this->packageConfig($broken),
                )->isFailed(),
                'Ein Knoten mit '.json_encode($broken).' haette rot gehen muessen.'
            );
        }

        Http::assertNothingSent();
    }

    public function test_a_missing_address_names_itself_outside_a_test_run(): void
    {
        // Der haeufigste Betriebsfall: der Ablauf startete aus einem Ereignis,
        // das keine Adresse trug, und `{{ student.email }}` loeste zu nichts
        // auf. Die Meldung muss das Feld benennen, sonst sucht jemand im
        // falschen System.
        Http::fake();

        foreach ([CreateStudentAction::class, GrantPackageAction::class] as $class) {
            $config = $class === CreateStudentAction::class
                ? ['name' => 'Nina Sömmer']
                : array_diff_key($this->packageConfig(), ['email' => null]);

            $result = app($class)->execute(AutomationContext::make(), $config);

            $this->assertTrue($result->isFailed());
            $this->assertSame('email', $result->output['missing_data_reference']);
        }

        Http::assertNothingSent();
    }

    public function test_the_address_is_taken_from_the_context_when_the_field_is_empty(): void
    {
        // Der Normalfall im Editor: das Feld bleibt leer und der Wert kommt aus
        // dem Auslöser. Ohne diesen Rueckgriff muesste jeder Ablauf das Token
        // von Hand eintragen.
        Http::fake([
            self::BASE.'/api/partner/v1/students' => Http::response(['data' => ['id' => 1, 'created' => true]], 200),
        ]);

        $result = app(CreateStudentAction::class)->execute(
            AutomationContext::make(['student' => ['email' => 'nina@example.com']]),
            ['name' => 'Nina Sömmer'],
        );

        $this->assertTrue($result->isSuccess());

        Http::assertSent(fn (Request $request) => $request->data()['email'] === 'nina@example.com');
    }

    // --- Helfer -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function packageConfig(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'nina@example.com',
            'session_type_id' => '019bffde-9c10-7246-be91-3eca56c5d7dd',
            'package_type' => 'one_time',
            'total_sessions' => 3,
            'expires_months' => 4,
        ];
    }
}
