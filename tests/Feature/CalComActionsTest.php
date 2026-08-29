<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Integrations\CalCom\Actions\CancelBookingAction;
use Goldnead\StatamicAutomations\Integrations\CalCom\Actions\CreateBookingAction;
use Goldnead\StatamicAutomations\Integrations\CalCom\Actions\GetSlotsAction;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComClient;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComResult;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Tests\Fixtures\CalComApiFake;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Die Gegenrichtung zu den fuenf Auslösern: die drei Aktionen, die zu cal.com
 * hinausrufen.
 *
 * Es sind drei, und das ist eine Entscheidung. Die API v2 kann daneben
 * verlegen, bestaetigen, ablehnen, Terminarten und Verfuegbarkeiten schreiben,
 * Teams verwalten. Gebaut ist, was ein Ablauf heute ruft: absagen, freie Zeiten
 * holen, anlegen.
 *
 * Was diese Datei festhaelt:
 *
 *   - **Jede Operation schickt die Version, die ihr Endpunkt will.** Der Kern
 *     dieses Anschlusses. cal.com versioniert je Endpunkt, und bei der
 *     falschen Version antwortet es teils mit 404 und teils mit einer stillen
 *     200. Die Attrappe nimmt das so streng wie das Original
 *     ({@see CalComApiFake}).
 *   - **Ohne Schluessel passiert nichts.** Kein Aufruf ins Leere.
 *   - **Ein Fehlschlag zerreisst den Ablauf nicht.**
 *   - **Ein doppelter Lauf richtet keinen Schaden an**, und der Knoten sagt,
 *     ob dieser Lauf es getan hat oder ein frueherer.
 *   - **Leer ist nicht gleich leer.** Eine unbekannte Terminart antwortet bei
 *     cal.com genauso wie ein voller Kalender.
 *   - **Ein Testlauf sagt nichts ab und legt nichts an.**
 */
class CalComActionsTest extends TestCase
{
    protected CalComApiFake $cal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cal = new CalComApiFake;
    }

    // --- die Versions-Kopfzeile ---------------------------------------------

    /**
     * Der Test, der die Konstanten im Client zusammenhaelt.
     *
     * `self::VERSION_BOOKINGS` an einem Terminart-Pfad ist syntaktisch
     * einwandfrei und faellt keinem Linter auf. Was auffaellt, ist diese
     * Aufstellung: sie laeuft jede Operation einmal und haelt fest, welche
     * Version an welchem Pfad ankam.
     */
    public function test_every_operation_sends_the_version_its_endpoint_wants(): void
    {
        // Die Terminart ist eingetragen, ihr Kalender ist leer. Damit laeuft
        // auch die Gegenprobe in GetSlotsAction an, und die dritte
        // Endpunkt-Familie kommt in die Aufstellung; auf dem Erfolgspfad
        // fragt sie niemand.
        $this->cal->eventType('5784955')->booking('abc123')->install();

        app(GetSlotsAction::class)->execute(AutomationContext::make(), $this->slotConfig());
        app(CancelBookingAction::class)->execute(AutomationContext::make(), ['booking_uid' => 'abc123', 'reason' => 'Weil.']);
        app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig());

        $seen = [];

        foreach ($this->cal->calls as $call) {
            $seen[$this->family($call['path'])][$call['version'] ?? '(keine)'] = true;
        }

        $this->assertSame(
            [
                '/v2/bookings' => [CalComApiFake::VERSION_BOOKINGS => true],
                '/v2/event-types' => [CalComApiFake::VERSION_EVENT_TYPES => true],
                '/v2/slots' => [CalComApiFake::VERSION_SLOTS => true],
            ],
            $this->sorted($seen),
            'Jede Endpunkt-Familie darf genau die eine Version sehen, die sie verlangt.',
        );
    }

    /**
     * Und die Gegenprobe: die Attrappe ist wirklich streng.
     *
     * Ohne diesen Test belegte der obige nur, dass Kopfzeilen mitgeschickt
     * wurden. Eine Attrappe, die jede Version annimmt, wuerde einen Client mit
     * der falschen genauso gruen durchwinken — und dann pruefte die ganze
     * Datei nur sich selbst.
     */
    public function test_the_fake_answers_a_wrong_version_the_way_cal_com_does(): void
    {
        $this->cal->eventType('5784955')->booking('abc123')->install();

        $wrongOnSlots = Http::withToken(CalComApiFake::KEY)
            ->withHeaders(['cal-api-version' => CalComApiFake::VERSION_BOOKINGS])
            ->get(CalComApiFake::BASE.'/v2/slots', ['eventTypeId' => '5784955', 'start' => 'a', 'end' => 'b']);

        $this->assertSame(404, $wrongOnSlots->status());
        $this->assertStringStartsWith('Cannot GET', (string) $wrongOnSlots->json('error.message'));

        $wrongOnEventTypes = Http::withToken(CalComApiFake::KEY)
            ->withHeaders(['cal-api-version' => CalComApiFake::VERSION_SLOTS])
            ->get(CalComApiFake::BASE.'/v2/event-types/5784955');

        $this->assertSame(404, $wrongOnEventTypes->status());

        // Der stille Fall, und der Grund, warum eine Pruefung des Umschlags
        // allein nicht reicht: hier stimmt der Umschlag, und darin steht etwas
        // ganz anderes.
        $wrongOnBookings = Http::withToken(CalComApiFake::KEY)
            ->withHeaders(['cal-api-version' => CalComApiFake::VERSION_EVENT_TYPES])
            ->get(CalComApiFake::BASE.'/v2/bookings/abc123');

        $this->assertSame(200, $wrongOnBookings->status());
        $this->assertSame('success', $wrongOnBookings->json('status'));
        $this->assertNull($wrongOnBookings->json('data.uid'));
    }

    /**
     * Und was davon beim Ablauf ankommt.
     *
     * Der 404 aus einer falschen Version liest sich wie \"diesen Termin gibt es
     * nicht\". Wer die beiden verwechselt, sucht im falschen System. Die
     * Meldung muss deshalb die Version nennen.
     */
    public function test_a_wrong_version_fails_recognisably_and_not_as_nothing_found(): void
    {
        $this->cal->slotsFor('5784955', ['2026-12-14' => ['2026-12-14T10:30:00.000Z']])->install();

        $result = app(WrongVersionSlotsAction::class)->execute(AutomationContext::make(), $this->slotConfig());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('cal-api-version', (string) $result->error);
        $this->assertStringContainsString('/v2/slots', (string) $result->error);
    }

    /**
     * Der stille Fall bis ans Ende durchgespielt.
     *
     * Eine falsche Version auf einem Termin-Endpunkt gibt 200 mit korrektem
     * Umschlag und einer anderen Form darin. Ein nachgiebiger Knoten meldete
     * hier eine Absage, die nicht stattgefunden hat, und jemand faende sich in
     * einem Termin wieder, den er abgesagt glaubte.
     */
    public function test_a_silently_wrong_shape_does_not_become_a_cancellation_that_never_happened(): void
    {
        $this->cal->booking('abc123')->install();

        $result = app(WrongVersionCancelAction::class)->execute(
            AutomationContext::make(),
            ['booking_uid' => 'abc123', 'reason' => 'Weil.'],
        );

        $this->assertTrue($result->isFailed(), 'Ohne Beleg darf keine Absage behauptet werden.');
        $this->assertSame('accepted', $this->cal->bookingStatus('abc123'), 'Und abgesagt wurde drueben auch nichts.');
    }

    // --- ohne Schluessel passiert nichts ------------------------------------

    public function test_without_an_api_key_the_actions_do_nothing_and_say_so(): void
    {
        Http::fake();

        foreach ([null, '', '   '] as $key) {
            config()->set('automations.integrations.cal_com.api_key', $key);

            foreach ([
                [CancelBookingAction::class, ['booking_uid' => 'abc123', 'reason' => 'Weil.']],
                [GetSlotsAction::class, $this->slotConfig()],
                [CreateBookingAction::class, $this->bookingConfig()],
            ] as [$class, $config]) {
                $result = app($class)->execute(AutomationContext::make(), $config);

                $this->assertTrue($result->isFailed(), $class.' haette rot gehen muessen.');
                $this->assertStringContainsString('not configured', (string) $result->error);
            }
        }

        // Und ausdruecklich: es wurde nicht ins Leere gerufen.
        Http::assertNothingSent();
    }

    // --- absagen ------------------------------------------------------------

    public function test_cancelling_sends_the_reason_and_reports_that_this_run_did_it(): void
    {
        $this->cal->booking('abc123')->install();

        $result = app(CancelBookingAction::class)->execute(
            AutomationContext::make(),
            ['booking_uid' => 'abc123', 'reason' => '  Zahlung geplatzt  '],
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('cancelled', $result->output['status']);
        $this->assertTrue($result->output['cancelled']);
        $this->assertFalse($result->output['already_cancelled']);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/cancel')) {
                return false;
            }

            $this->assertSame('Zahlung geplatzt', $request->data()['cancellationReason']);

            return true;
        });

        // Und genau einmal. `assertSent` ist zufrieden, sobald **eine**
        // Anfrage passt; bei einer Operation, die nicht wiederholbar ist, ist
        // das die falsche Zusicherung. Und die Gegenprobe darf auf dem
        // Erfolgspfad gar nicht laufen.
        $this->assertSame(
            [['method' => 'POST', 'path' => '/v2/bookings/abc123/cancel', 'version' => CalComApiFake::VERSION_BOOKINGS]],
            $this->cal->calls,
        );
    }

    /**
     * Der doppelte Lauf, der Grund fuer die halbe Aktion.
     *
     * cal.com kennt fuer die Absage keinen Idempotenz-Schluessel und lehnt den
     * zweiten Aufruf mit 400 ab. Durchgereicht ginge der Knoten dann rot fuer
     * genau das Ergebnis, das er herstellen sollte.
     */
    public function test_a_second_run_cancels_nothing_twice_and_says_which_run_did_it(): void
    {
        $this->cal->booking('abc123')->install();

        $first = app(CancelBookingAction::class)->execute(
            AutomationContext::make(),
            ['booking_uid' => 'abc123', 'reason' => 'Zahlung geplatzt'],
        );

        $second = app(CancelBookingAction::class)->execute(
            AutomationContext::make(),
            ['booking_uid' => 'abc123', 'reason' => 'Zahlung geplatzt'],
        );

        $this->assertTrue($first->isSuccess());
        $this->assertTrue($second->isSuccess(), 'Der zweite Lauf hat erreicht, was er sollte, und geht nicht rot.');

        // Und die Auskunft, an der eine Mail haengt: nur der erste Lauf hat es
        // getan. Ohne diese Unterscheidung ginge die Absage-Mail zweimal raus.
        $this->assertTrue($first->output['cancelled']);
        $this->assertFalse($second->output['cancelled']);
        $this->assertTrue($second->output['already_cancelled']);
    }

    /**
     * Der Fall, den man beim Bauen vergisst und im Betrieb bezahlt.
     *
     * Die Absage geht hinaus, cal.com fuehrt sie aus, und die Antwort kommt
     * nicht zurueck. Der Termin ist danach abgesagt, und ob dieser Lauf es war,
     * ist von hier aus nicht zu erkennen.
     *
     * `already_cancelled` zu melden waere hier die bequeme Antwort und die
     * schlimmere Haelfte des Fehlers: gruen, ohne Alarm, `cancelled` bleibt
     * `false`, und die Absage-Mail ginge in **keinem** Lauf hinaus.
     */
    public function test_a_cancellation_whose_answer_got_lost_is_not_credited_to_an_earlier_run(): void
    {
        config()->set('automations.integrations.cal_com.api_key', CalComApiFake::KEY);
        config()->set('automations.integrations.cal_com.api_url', CalComApiFake::BASE);

        // Schreiben scheitert, Lesen gelingt und zeigt einen abgesagten Termin.
        // Genau die Lage nach einer Zeitueberschreitung, in der cal.com die
        // Absage schon ausgefuehrt hatte.
        Http::fake([
            CalComApiFake::BASE.'/v2/bookings/*/cancel' => Http::response('<html>gateway</html>', 504),
            CalComApiFake::BASE.'/v2/bookings/*' => Http::response(
                ['status' => 'success', 'data' => ['uid' => 'abc123', 'status' => 'cancelled']],
                200,
            ),
        ]);

        $result = app(CancelBookingAction::class)->execute(
            AutomationContext::make(),
            ['booking_uid' => 'abc123', 'reason' => 'Zahlung geplatzt'],
        );

        $this->assertTrue($result->isFailed(), 'Unklare Urheberschaft gehoert vor einen Menschen, nicht in einen gruenen Knoten.');
        $this->assertFalse($result->output['already_cancelled'], 'Kein frueherer Lauf ist belegt.');
        $this->assertFalse($result->output['cancelled']);
        // Aber der Zustand drueben wird berichtet: wer aufraeumt, soll nicht
        // erst nachsehen muessen, ob der Termin noch steht.
        $this->assertSame('cancelled', $result->output['status']);
    }

    public function test_an_unknown_booking_stays_a_failure(): void
    {
        // Die Grenze der Nachsicht von oben: ein Termin, den es nicht gibt, ist
        // kein zweiter Lauf, sondern ein Fehler, und muss einer bleiben.
        $this->cal->install();

        $result = app(CancelBookingAction::class)->execute(
            AutomationContext::make(),
            ['booking_uid' => 'gibtesnicht', 'reason' => 'Weil.'],
        );

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not found', (string) $result->error);
    }

    public function test_an_empty_uid_names_the_empty_field_and_sends_nothing(): void
    {
        // `/v2/bookings//cancel` antwortet mit einer Weiterleitung, nicht mit
        // einem Fehler. Was daraus entstuende, spraeche vom Endpunkt, waehrend
        // in Wahrheit ein Feld im Ablauf leer geblieben ist.
        $this->cal->install();

        $result = app(CancelBookingAction::class)->execute(
            AutomationContext::make(),
            ['reason' => 'Weil.'],
        );

        $this->assertTrue($result->isFailed());
        $this->assertSame('booking_uid', $result->output['missing_data_reference']);

        Http::assertNothingSent();
    }

    // --- freie Zeiten -------------------------------------------------------

    public function test_slots_come_back_flat_sorted_and_capped(): void
    {
        $this->cal
            ->eventType('5784955')
            ->slotsFor('5784955', [
                '2026-12-15' => ['2026-12-15T09:00:00.000Z', '2026-12-15T09:30:00.000Z'],
                '2026-12-14' => ['2026-12-14T10:30:00.000Z', '2026-12-14T11:00:00.000Z'],
            ])
            ->install();

        $result = app(GetSlotsAction::class)->execute(
            AutomationContext::make(),
            $this->slotConfig(['limit' => 3]),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame([
            '2026-12-14T10:30:00.000Z',
            '2026-12-14T11:00:00.000Z',
            '2026-12-15T09:00:00.000Z',
        ], $result->output['slots']);

        // `count` zaehlt, was weitergegeben wird, `total`, was cal.com kennt.
        $this->assertSame(3, $result->output['count']);
        $this->assertSame(4, $result->output['total']);
        $this->assertSame('2026-12-14T10:30:00.000Z', $result->output['first']);

        // Die Gruppierung zeigt nicht mehr als die Liste. Sonst schluege eine
        // Mail, die nach Tagen gliedert, Zeiten vor, die der Knoten
        // abgeschnitten hat.
        $this->assertSame([
            '2026-12-14' => ['2026-12-14T10:30:00.000Z', '2026-12-14T11:00:00.000Z'],
            '2026-12-15' => ['2026-12-15T09:00:00.000Z'],
        ], $result->output['by_date']);
    }

    /**
     * Der stille Fehler, gegen den die halbe Aktion geschrieben ist.
     *
     * cal.com antwortet auf eine unbekannte Terminart mit `{}` und Status 200,
     * nicht zu unterscheiden von einem vollen Kalender. Ein Ablauf mit einer
     * vertippten Kennung wuerde ohne Gegenprobe monatelang still nichts
     * vorschlagen.
     */
    public function test_an_unknown_event_type_goes_red_instead_of_looking_like_a_full_calendar(): void
    {
        $this->cal->eventType('5784955')->install();

        $unknown = app(GetSlotsAction::class)->execute(
            AutomationContext::make(),
            $this->slotConfig(['event_type_id' => '999999']),
        );

        $this->assertTrue($unknown->isFailed());
        $this->assertStringContainsString('999999', (string) $unknown->error);

        // Und die Gegenprobe zur Gegenprobe: eine Terminart, die es gibt, deren
        // Kalender aber voll ist, ist kein Fehler. Sonst waere die Pruefung
        // oben nur ein anderer Weg, immer rot zu gehen.
        $full = app(GetSlotsAction::class)->execute(AutomationContext::make(), $this->slotConfig());

        $this->assertTrue($full->isSuccess());
        $this->assertSame(0, $full->output['count']);
        $this->assertSame([], $full->output['slots']);
    }

    public function test_an_empty_answer_whose_check_did_not_go_through_is_not_reported_as_a_full_calendar(): void
    {
        // Wenn die Gegenprobe selbst scheitert, ist nicht belegt, dass der
        // Kalender voll ist. \"Wir wissen es nicht\" als \"nichts frei\"
        // auszugeben waere genau der Fehler, den sie verhindern soll.
        // Ohne die Attrappe, weil hier gerade der Fall gebraucht wird, den sie
        // nicht nachstellt: cal.com antwortet auf die Gegenprobe gar nicht.
        config()->set('automations.integrations.cal_com.api_key', CalComApiFake::KEY);
        config()->set('automations.integrations.cal_com.api_url', CalComApiFake::BASE);

        Http::fake([
            CalComApiFake::BASE.'/v2/slots*' => Http::response(['status' => 'success', 'data' => []], 200),
            CalComApiFake::BASE.'/v2/event-types/*' => Http::response('<html>gateway</html>', 502),
        ]);

        $result = app(GetSlotsAction::class)->execute(AutomationContext::make(), $this->slotConfig());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('proves nothing', (string) $result->error);
    }

    public function test_free_times_that_cannot_be_read_go_red_instead_of_reading_as_none(): void
    {
        // Eine Formaenderung an /v2/slots (ein umbenanntes `start`, eine flache
        // Liste statt der Datums-Schluessel) darf nicht in denselben Topf
        // fallen wie ein voller Kalender. Sonst schlaegt ein Ablauf monatelang
        // nichts vor, und nichts sieht kaputt aus.
        config()->set('automations.integrations.cal_com.api_key', CalComApiFake::KEY);
        config()->set('automations.integrations.cal_com.api_url', CalComApiFake::BASE);

        Http::fake([
            CalComApiFake::BASE.'/v2/slots*' => Http::response([
                'status' => 'success',
                'data' => ['2026-12-14' => [['startTime' => '2026-12-14T10:30:00.000Z']]],
            ], 200),
        ]);

        $result = app(GetSlotsAction::class)->execute(AutomationContext::make(), $this->slotConfig());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('could not read', (string) $result->error);

        // Und die Gegenprobe wurde gar nicht erst gefragt: die Terminart ist
        // nicht das Problem.
        Http::assertNotSent(fn (Request $request) => str_contains((string) $request->url(), '/v2/event-types'));
    }

    public function test_the_cross_check_stays_out_of_the_way_when_there_are_slots(): void
    {
        // Eine Verhaltensbehauptung im Docblock ("kostet im Normalfall
        // nichts"), deren Bruch sonst nur auf cal.coms Rechnung auffiele.
        $this->cal
            ->eventType('5784955')
            ->slotsFor('5784955', ['2026-12-14' => ['2026-12-14T10:30:00.000Z']])
            ->install();

        app(GetSlotsAction::class)->execute(AutomationContext::make(), $this->slotConfig());

        $this->assertSame(['/v2/slots'], array_column($this->cal->calls, 'path'));
    }

    // --- anlegen ------------------------------------------------------------

    public function test_a_booking_on_an_event_type_without_confirmation_stands(): void
    {
        $this->cal->eventType('5784955', ['confirmationPolicy' => ['disabled' => true]])->install();

        $result = app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('accepted', $result->output['status']);
        $this->assertTrue($result->output['confirmed']);
        $this->assertNotEmpty($result->output['uid']);
    }

    /**
     * Der Fall, der wie ein Fehler aussieht und keiner ist.
     *
     * Ob ein neuer Termin steht oder auf Bestaetigung wartet, entscheidet die
     * `confirmationPolicy` der Terminart und nichts sonst. Der Knoten gibt
     * deshalb den Zustand zurueck und nicht ein \"erledigt\": gruen heisst
     * \"cal.com hat es angenommen\", nicht \"der Termin steht\".
     */
    public function test_a_booking_on_an_event_type_that_requires_confirmation_comes_back_pending_and_says_so(): void
    {
        $this->cal->eventType('5784955', ['confirmationPolicy' => ['type' => 'always']])->install();

        $result = app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig());

        $this->assertTrue($result->isSuccess(), 'Angenommen ist angenommen; der Knoten ist nicht rot.');
        $this->assertSame('pending', $result->output['status']);
        $this->assertFalse($result->output['confirmed'], 'Aber der Termin steht nicht, und daran haengt sich ein Ablauf.');
    }

    /**
     * Was ein doppelter Lauf anrichtet: nichts, solange der Zeitpunkt derselbe
     * ist. Der Schutz kommt vom Kalender, nicht von der API.
     */
    public function test_a_second_run_on_the_same_slot_creates_no_second_booking(): void
    {
        $this->cal->eventType('5784955', ['confirmationPolicy' => ['disabled' => true]])->install();

        $first = app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig());
        $second = app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig());

        $this->assertTrue($first->isSuccess());
        $this->assertTrue($second->isFailed(), 'cal.com lehnt den belegten Zeitpunkt ab, und das bleibt ein roter Knoten.');
        $this->assertTrue($second->output['slot_unavailable'], 'Wer aufraeumt, soll das an der Ausgabe sehen.');
        $this->assertStringContainsString('already has booking', (string) $second->error);

        // Und der andere Zustand desselben Feldes, sonst belegte der Test nur,
        // dass dort irgendwo `true` steht. Eine unbekannte Terminart ist ein
        // Fehlschlag, aber kein belegter Zeitpunkt.
        $unknown = app(CreateBookingAction::class)->execute(
            AutomationContext::make(),
            $this->bookingConfig(['event_type_id' => '999999']),
        );

        $this->assertTrue($unknown->isFailed());
        $this->assertFalse($unknown->output['slot_unavailable']);
    }

    /**
     * Was der Knoten wirklich hinausschickt.
     *
     * Ohne diese Zusicherung belegen die Tests darueber nur, was zurueckkam.
     * Sie blieben gruen, wenn die Aktion die Adresse gar nicht mitschickte,
     * die Zeitzone weg liesse oder die Adresse nicht kleinschriebe — alles
     * Dinge, fuer die sie eigene Pruefungen und eigene Begruendungen hat.
     */
    public function test_the_booking_payload_carries_what_the_node_promises(): void
    {
        $this->cal->eventType('5784955', ['confirmationPolicy' => ['disabled' => true]])->install();

        app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig([
            'start' => '  2026-12-14T10:30:00.000Z  ',
            'attendee_name' => '  Nina Sömmer  ',
            'attendee_email' => '  Nina@Example.COM  ',
            'attendee_language' => 'de',
        ]));

        Http::assertSent(function (Request $request) {
            if ((string) parse_url($request->url(), PHP_URL_PATH) !== '/v2/bookings') {
                return false;
            }

            $data = $request->data();

            $this->assertSame(5784955, $data['eventTypeId'], 'Die Kennung geht als Zahl hinaus, nicht als Zeichenkette.');
            $this->assertSame('2026-12-14T10:30:00.000Z', $data['start']);
            $this->assertSame('Nina Sömmer', $data['attendee']['name']);
            $this->assertSame('nina@example.com', $data['attendee']['email'], 'Kleingeschrieben und getrimmt.');
            $this->assertSame('Europe/Berlin', $data['attendee']['timeZone']);
            $this->assertSame('de', $data['attendee']['language']);

            return true;
        });
    }

    public function test_an_optional_language_is_left_out_when_it_is_empty(): void
    {
        // Ein leeres Feld mitzuschicken ist nicht dasselbe wie es wegzulassen:
        // im Ablaufprotokoll stuende dann eine Sprache, die nie gewirkt hat.
        $this->cal->eventType('5784955', ['confirmationPolicy' => ['disabled' => true]])->install();

        app(CreateBookingAction::class)->execute(
            AutomationContext::make(),
            $this->bookingConfig(['attendee_language' => '  ']),
        );

        Http::assertSent(function (Request $request) {
            if ((string) parse_url($request->url(), PHP_URL_PATH) !== '/v2/bookings') {
                return false;
            }

            $this->assertArrayNotHasKey('language', $request->data()['attendee']);

            return true;
        });
    }

    public function test_a_booking_without_a_uid_is_not_reported_as_a_booking(): void
    {
        // Ein Termin ohne Kennung ist kein Termin. Gruen zu melden hiesse, den
        // naechsten Knoten mit einem leeren {{ node.uid }} weiterarbeiten zu
        // lassen, und der sagt dann einen Termin ab, den er nicht findet.
        config()->set('automations.integrations.cal_com.api_key', CalComApiFake::KEY);
        config()->set('automations.integrations.cal_com.api_url', CalComApiFake::BASE);

        Http::fake([
            CalComApiFake::BASE.'/v2/bookings' => Http::response(['status' => 'success', 'data' => ['status' => 'accepted']], 200),
        ]);

        $result = app(CreateBookingAction::class)->execute(AutomationContext::make(), $this->bookingConfig());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('no uid', (string) $result->error);
    }

    // --- ein Fehlschlag zerreisst den Ablauf nicht --------------------------

    public function test_a_server_that_is_not_reachable_comes_back_as_a_failure_and_not_as_an_exception(): void
    {
        config()->set('automations.integrations.cal_com.api_key', CalComApiFake::KEY);
        config()->set('automations.integrations.cal_com.api_url', CalComApiFake::BASE);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        foreach ([
            [CancelBookingAction::class, ['booking_uid' => 'abc123', 'reason' => 'Weil.']],
            [GetSlotsAction::class, $this->slotConfig()],
            [CreateBookingAction::class, $this->bookingConfig()],
        ] as [$class, $config]) {
            $result = app($class)->execute(AutomationContext::make(), $config);

            $this->assertTrue($result->isFailed(), $class);
            $this->assertStringContainsString('could not be reached', (string) $result->error);
            // Die cURL-Meldung wandert mit, weil in ihr steht, ob die Anfrage
            // draussen war: danach entscheidet sich, ob ein Wiederholen einen
            // zweiten Termin anlegen kann.
            $this->assertStringContainsString('timed out', (string) $result->error);
        }
    }

    /**
     * Und der ganze Weg: der Knoten geht rot, die Maschine bleibt heil.
     *
     * Gemessen ueber den `NodeExecutor`, weil genau dort der Unterschied
     * liegt: eine Aktion, die wirft statt ein Ergebnis zurueckzugeben, macht
     * aus einem roten Knoten einen abgebrochenen Lauf, und der Rest des
     * Ablaufs passiert dann nicht mehr.
     */
    public function test_a_failing_action_leaves_the_engine_in_control(): void
    {
        $this->cal->install();

        $node = new AutomationNode([
            'node_key' => 'absage',
            'type' => 'cal_com.cancel_booking',
            'config' => ['booking_uid' => 'gibtesnicht', 'reason' => 'Weil.'],
        ]);

        $result = app(NodeExecutor::class)->execute($node, AutomationContext::make());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not found', (string) $result->error);
        $this->assertArrayNotHasKey('exception', $result->output);
    }

    // --- ein Testlauf sagt nichts ab und legt nichts an ---------------------

    public function test_a_test_run_previews_the_writing_actions_and_sends_nothing(): void
    {
        $this->cal->install();

        $cancel = app(CancelBookingAction::class)->execute(
            AutomationContext::make([], testMode: true),
            ['booking_uid' => 'abc123', 'reason' => 'Weil.'],
        );

        $this->assertTrue($cancel->isSuccess());
        $this->assertSame('abc123', $cancel->output['preview']['booking_uid']);

        $create = app(CreateBookingAction::class)->execute(
            AutomationContext::make([], testMode: true),
            $this->bookingConfig(),
        );

        $this->assertTrue($create->isSuccess());
        $this->assertSame(5784955, $create->output['preview']['eventTypeId']);

        Http::assertNothingSent();
    }

    /**
     * Freie Zeiten zu lesen faellt ausdruecklich nicht darunter.
     *
     * Der Knoten aendert drueben nichts, und eine Vorschau aus erfundenen
     * Zeiten waere nichts wert: wer im Testlauf sehen will, ob sein Ablauf
     * brauchbare Termine vorschlaegt, muss die echten sehen.
     */
    public function test_a_test_run_really_reads_the_free_slots(): void
    {
        $this->cal
            ->eventType('5784955')
            ->slotsFor('5784955', ['2026-12-14' => ['2026-12-14T10:30:00.000Z']])
            ->install();

        $result = app(GetSlotsAction::class)->execute(
            AutomationContext::make([], testMode: true),
            $this->slotConfig(),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['2026-12-14T10:30:00.000Z'], $result->output['slots']);
    }

    public function test_a_test_run_still_goes_red_on_a_misconfigured_node(): void
    {
        // Die Grenze aus ActionResult::missingDataReference(): statische
        // Konfiguration wird vor dem Testmodus geprueft, Datenreferenzen
        // danach.
        $this->cal->install();

        $context = fn () => AutomationContext::make([], testMode: true);

        $this->assertTrue(
            app(CancelBookingAction::class)->execute($context(), ['booking_uid' => 'abc123', 'reason' => ' '])->isFailed(),
            'Eine Absage ohne Grund ist ein falsch eingerichteter Knoten.',
        );

        foreach ([
            ['event_type_id' => ''],
            ['start' => ''],
            ['end' => ''],
        ] as $broken) {
            $this->assertTrue(
                app(GetSlotsAction::class)->execute($context(), $this->slotConfig($broken))->isFailed(),
                'Ein Knoten mit '.json_encode($broken).' haette rot gehen muessen.',
            );
        }

        foreach ([
            ['event_type_id' => 'keine-zahl'],
            ['start' => ''],
            ['attendee_name' => ''],
            ['attendee_time_zone' => ''],
        ] as $broken) {
            $this->assertTrue(
                app(CreateBookingAction::class)->execute($context(), $this->bookingConfig($broken))->isFailed(),
                'Ein Knoten mit '.json_encode($broken).' haette rot gehen muessen.',
            );
        }

        Http::assertNothingSent();
    }

    // --- Handwerkszeug ------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function slotConfig(array $overrides = []): array
    {
        return $overrides + [
            'event_type_id' => '5784955',
            'start' => '2026-12-14',
            'end' => '2026-12-16',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function bookingConfig(array $overrides = []): array
    {
        return $overrides + [
            'event_type_id' => '5784955',
            'start' => '2026-12-14T10:30:00.000Z',
            'attendee_name' => 'Nina Sömmer',
            'attendee_email' => 'Nina@Example.com',
            'attendee_time_zone' => 'Europe/Berlin',
        ];
    }

    protected function family(string $path): string
    {
        foreach (['/v2/bookings', '/v2/event-types', '/v2/slots'] as $family) {
            if (str_starts_with($path, $family)) {
                return $family;
            }
        }

        return $path;
    }

    /**
     * @param  array<string, array<string, bool>>  $seen
     * @return array<string, array<string, bool>>
     */
    protected function sorted(array $seen): array
    {
        ksort($seen);

        foreach ($seen as $family => $versions) {
            ksort($versions);
            $seen[$family] = $versions;
        }

        return $seen;
    }
}

/**
 * Ein Client mit absichtlich falsch gesetzten Versionen.
 *
 * Es gibt keinen anderen Weg, das zu pruefen: die richtigen Versionen sind im
 * echten Client Konstanten, und eine Konstante laesst sich zur Laufzeit nicht
 * verstellen. Was hier nachgestellt wird, ist der Tippfehler, den ein
 * Weiterbauender macht — `VERSION_BOOKINGS` an einem Terminart-Pfad, weil die
 * Zeile darueber es so machte.
 *
 * Was der Test daran festhaelt, ist nicht, dass es schiefgeht (das ist cal.coms
 * Sache), sondern **wie**: als benannter Versionsfehler und nicht als \"diesen
 * Termin gibt es nicht\".
 */
class WrongVersionClient extends CalComClient
{
    public function slots(array $query): CalComResult
    {
        return $this->send('get', '/v2/slots', '2024-08-13', $query);
    }

    public function cancelBooking(string $uid, string $reason): CalComResult
    {
        return $this->send('post', '/v2/bookings/'.rawurlencode($uid).'/cancel', '2024-06-14', [
            'cancellationReason' => $reason,
        ]);
    }

    public function booking(string $uid): CalComResult
    {
        return $this->send('get', '/v2/bookings/'.rawurlencode($uid), '2024-06-14');
    }
}

class WrongVersionSlotsAction extends GetSlotsAction
{
    public function __construct()
    {
        parent::__construct(new WrongVersionClient);
    }
}

class WrongVersionCancelAction extends CancelBookingAction
{
    public function __construct()
    {
        parent::__construct(new WrongVersionClient);
    }
}
