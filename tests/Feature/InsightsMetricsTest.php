<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicAutomations\Integrations\Insights\AutomationMetric;
use Goldnead\StatamicAutomations\Integrations\Insights\DurationP50;
use Goldnead\StatamicAutomations\Integrations\Insights\Failures;
use Goldnead\StatamicAutomations\Integrations\Insights\OptOuts;
use Goldnead\StatamicAutomations\Integrations\Insights\Runs;
use Goldnead\StatamicAutomations\Integrations\Insights\SuccessRate;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die fuenf Betriebszahlen, die dieses Addon dem Analytics-Addon anbietet.
 *
 * Jede Erwartung unten ist von Hand aus derselben kleinen Vorlage gerechnet.
 * Das ist der Punkt der Datei: eine Abfrage, die sich verschiebt, faellt als
 * Rechenfehler auf und nicht als gruene Suite ueber einer anderen Auswertung.
 *
 * Gegen einen Stellvertreter des Vertrags getestet und nicht gegen das echte
 * Paket, aus demselben Grund, aus dem das Geschwister ein `suggest` ist: ein
 * Test, der es installiert braeuchte, wuerde das Gegenteil dessen belegen, was
 * dieses Addon behauptet. Warum das eine von Hand geladene Datei ist und kein
 * Autoload-Pfad, steht in `tests/Fakes/insights-contracts.php`.
 *
 * Die Zeit ist eingefroren. Die Eimer werden als konkrete Daten geprueft, und
 * eine Suite, die um Mitternacht liefe, wuerde sonst einmal pro Nacht aus
 * Gruenden scheitern, die mit dem Code nichts zu tun haben.
 */
class InsightsMetricsTest extends TestCase
{
    /** Der Tag, von dem aus alles hier gemessen wird. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Sammelt ein, was der ServiceProvider registriert. */
    protected object $insights;

    protected function setUp(): void
    {
        // Vor der Anwendung, beides. Die Vertraege muessen da sein, bevor eine
        // Kennzahl-Klasse geladen wird, und die Fassade, bevor der Provider in
        // seinem `booted()`-Rueckruf fragt, ob es sie gibt — ein Rueckruf, der
        // schon gelaufen ist, bekommt keine zweite Gelegenheit.
        //
        // Die Basisklasse als eigene Datei und ohne Absicherung im Kopf: sie
        // ist eine Byte-fuer-Byte-Kopie, und die Absicherung sitzt deshalb
        // hier. Siehe InsightsContractsMatchTest.
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        if (! class_exists(TableMetric::class, false)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Strenger als die echte Verwaltung, mit Absicht.
             *
             * Die echte nimmt eine Kennzahl auch ohne Handle an und ermittelt
             * ihn, indem sie sie baut. Das hier anzunehmen hiesse, dass der
             * Provider den Handle weglassen koennte und trotzdem richtig
             * aussieht — und der Handle ist die Haelfte, die in gespeicherten
             * Ansichten und URLs landet.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- Die Vorlage --------------------------------------------------------

    /**
     * Zwei Automationen, acht Laeufe, zwei Ausstiege.
     *
     * Klein genug, um sie im Kopf zu addieren, und jeder unangenehme Fall ist
     * drin: ein Testlauf, ein Lauf, der noch in der Warteschlange steht und
     * darum kein `started_at` hat, ein Lauf ausserhalb des Fensters, ein Lauf
     * ohne Ausloeser, einer, der wartet und darum keine Dauer hat, und einer,
     * den ein Stopp-Knoten beendet hat.
     *
     * Im Fenster (11.–20.08.): fuenf Laeufe, davon zwei erfolgreich, einer
     * gescheitert, einer wartend, einer gestoppt.
     */
    protected function fixture(): void
    {
        Automation::create(['uuid' => 'uuid-willkommen', 'name' => 'Willkommensserie', 'handle' => 'willkommen']);
        Automation::create(['uuid' => 'uuid-nachfass', 'name' => 'Nachfassen', 'handle' => 'nachfass']);

        $this->lauf([
            'automation_uuid' => 'uuid-willkommen',
            'trigger_type' => 'entry.published',
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2026-08-15 10:00:00',
            'duration_ms' => 1000,
        ]);

        $this->lauf([
            'automation_uuid' => 'uuid-willkommen',
            'trigger_type' => 'entry.published',
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2026-08-15 18:00:00',
            'duration_ms' => 3000,
        ]);

        $this->lauf([
            'automation_uuid' => 'uuid-willkommen',
            'trigger_type' => 'entry.published',
            'status' => AutomationRun::STATUS_FAILED,
            'started_at' => '2026-08-18 09:00:00',
            'duration_ms' => 5000,
            'error_message' => 'Der Dienst antwortete mit 500.',
        ]);

        // Wartet in einer Verzoegerung: kein Urteil und keine Dauer.
        $this->lauf([
            'automation_uuid' => 'uuid-nachfass',
            'trigger_type' => 'form.submitted',
            'status' => AutomationRun::STATUS_WAITING,
            'started_at' => '2026-08-18 12:00:00',
            'duration_ms' => null,
        ]);

        // Ein Stopp-Knoten hat ihn beendet. Ausgestiegen, nicht gescheitert.
        // Und ohne Ausloeser: eine Zeile in der Aufteilung, keine Auslassung.
        $this->lauf([
            'automation_uuid' => 'uuid-nachfass',
            'trigger_type' => null,
            'status' => AutomationRun::STATUS_STOPPED,
            'started_at' => '2026-08-19 08:00:00',
            'duration_ms' => 2000,
        ]);

        // Ein Klick auf „Test" im Editor. Darf in keiner einzigen Zahl stehen.
        $this->lauf([
            'automation_uuid' => 'uuid-willkommen',
            'trigger_type' => 'entry.published',
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2026-08-16 10:00:00',
            'duration_ms' => 100,
            'is_test' => true,
        ]);

        // Angelegt, aber nie gestartet. Ohne `started_at` gibt es nichts zu
        // datieren — er erscheint, sobald er anfaengt.
        $this->lauf([
            'automation_uuid' => 'uuid-nachfass',
            'trigger_type' => 'form.submitted',
            'status' => AutomationRun::STATUS_QUEUED,
            'started_at' => null,
            'duration_ms' => null,
        ]);

        // Vor dem Fenster.
        $this->lauf([
            'automation_uuid' => 'uuid-willkommen',
            'trigger_type' => 'entry.published',
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2026-07-01 09:00:00',
            'duration_ms' => 9000,
        ]);

        $this->optOut('uuid-willkommen', 'anna@example.com', '2026-08-16 08:00:00');
        $this->optOut('uuid-willkommen', 'bruno@example.com', '2026-08-19 20:00:00');
        // Ausserhalb des Fensters.
        $this->optOut('uuid-nachfass', 'clara@example.com', '2026-07-04 08:00:00');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function lauf(array $overrides = []): AutomationRun
    {
        return AutomationRun::create(array_merge([
            'automation_uuid' => 'uuid-willkommen',
            'trigger_type' => 'entry.published',
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1000,
            'is_test' => false,
        ], $overrides));
    }

    protected function optOut(string $automationUuid, string $subject, string $at, int $brandId = 0): void
    {
        DB::table('automation_opt_outs')->insert([
            'uuid' => (string) Str::uuid(),
            'brand_id' => $brandId,
            'automation_uuid' => $automationUuid,
            'subject_key' => $subject,
            'opted_out_at' => $at,
            'source' => 'mail_link',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Die zehn Tage, in denen die Vorlage lebt, nach Tagen gebucketet. */
    protected function frage(string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
            $bucket,
        );
    }

    /** Ein leeres Fenster: alles davor. */
    protected function stillesFenster(): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-01')->startOfDay(), Carbon::parse('2026-08-05')->endOfDay()),
        );
    }

    /**
     * @param  array<int, array{key: string|null, label: string, value: int|float}>  $rows
     * @return array<string, int|float>
     */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- Die fuenf Zahlen ---------------------------------------------------

    /**
     * Alle Zahlen auf einmal, gegen von Hand gerechnete Summen.
     *
     * Ein Test statt fuenf, mit Absicht: sie stehen auf einem Schirm
     * nebeneinander und muessen zueinander passen. Eine Laufzahl, die sich
     * bewegt hat, ohne dass die Quote folgt, ist der Fehlschlag, der sich zu
     * fangen lohnt — fuenf getrennte Tests waeren fuenf Gelegenheiten, einen
     * davon zu reparieren und die anderen stehen zu lassen.
     */
    #[Test]
    public function the_five_figures_match_what_the_runs_table_says(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(5, (new Runs)->value($frage), 'Laeufe: zwei erfolgreich, einer gescheitert, einer wartend, einer gestoppt');
        $this->assertSame(1, (new Failures)->value($frage), 'nur `failed`, nicht der gestoppte');

        // 2 von 3 mit einem Urteil. Der wartende und der gestoppte Lauf haben
        // keines und stehen in keinem der beiden Teile.
        $this->assertSame(66.7, (new SuccessRate)->value($frage), 'round(2 / 3 * 100, 1)');

        // 1000, 2000, 3000, 5000 ms -> nearest rank nimmt bei vier Werten den
        // zweiten: 2000 ms, also 2,0 Sekunden.
        $this->assertSame(2.0, (new DurationP50)->value($frage), 'Median von 1000/2000/3000/5000 ms in Sekunden');

        $this->assertSame(2, (new OptOuts)->value($frage), 'zwei Ausstiege im Fenster, einer davor');
    }

    /** Die Handles sind ein Versprechen. Sie landen in Ansichten und URLs. */
    #[Test]
    public function the_handles_units_and_group_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Runs::class, 'automations.runs', Unit::COUNT],
            [Failures::class, 'automations.failures', Unit::COUNT],
            [SuccessRate::class, 'automations.success_rate', Unit::PERCENT],
            [DurationP50::class, 'automations.duration_p50', Unit::DURATION],
            [OptOuts::class, 'automations.opt_outs', Unit::COUNT],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('statamic-automations::insights.group'), $metrik->group());
            $this->assertNotSame('', $metrik->label());
            $this->assertNotEmpty($metrik->description());
            $this->assertSame([], $metrik->meta($this->frage()));
        }
    }

    /** Der Provider bietet genau diese fuenf an, faul und mit Handle. */
    #[Test]
    public function the_provider_offers_every_figure_to_the_sibling(): void
    {
        $this->assertSame([
            'automations.runs' => Runs::class,
            'automations.failures' => Failures::class,
            'automations.success_rate' => SuccessRate::class,
            'automations.duration_p50' => DurationP50::class,
            'automations.opt_outs' => OptOuts::class,
        ], $this->insights->registered);
    }

    // -- Testlaeufe sind keine Zahlen ---------------------------------------

    /**
     * Ein Klick auf „Test" im Editor ist keine Person, die durch den Ablauf geht.
     *
     * Ohne den Ausschluss stuende die Vorlage bei sechs Laeufen, die
     * Erfolgsquote bei 75 statt 66,7 Prozent und der Median bei 1,0 statt 2,0
     * Sekunden — der Testlauf ist der schnellste von allen. Genau so laese sich
     * eine Erfolgsquote am besten auf den Automationen, die noch nie jemandem
     * etwas geschickt haben.
     */
    #[Test]
    public function a_test_run_is_in_no_figure_at_all(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(6, AutomationRun::query()
            ->whereBetween('started_at', ['2026-08-11 00:00:00', '2026-08-20 23:59:59'])
            ->count(), 'die Tabelle enthaelt den Testlauf sehr wohl');

        $this->assertSame(5, (new Runs)->value($frage));
        $this->assertSame(66.7, (new SuccessRate)->value($frage));
        $this->assertSame(2.0, (new DurationP50)->value($frage));

        $this->assertSame(
            ['2026-08-15' => 2, '2026-08-18' => 2, '2026-08-19' => 1],
            (new Runs)->series($frage),
            'und der 16. hat keinen Eimer, obwohl an dem Tag ein Testlauf lief',
        );
    }

    /**
     * Auch „alle Zeit" ist ein Zeitraum, in dem ein Lauf ohne Start nicht liegt.
     *
     * `started_at` ist nullable, und beim Preset `all` sind beide Grenzen des
     * Zeitraums `null` — die beiden Fenster-Bedingungen fallen dann ersatzlos
     * weg. Ohne das `whereNotNull` in {@see TableMetric::inPeriod()}
     * zaehlte die Kachel in genau diesem einen Fall jede Zeile mit, die je
     * geschrieben wurde, den nie gestarteten Lauf in der Warteschlange
     * eingeschlossen. Das ist der unangenehmste Ort fuer einen Fehler: er zeigt
     * sich nur im weitesten Bereich, wo niemand die Zahl nachrechnet.
     *
     * Ueber alle Zeit sind es sechs: die fuenf im Fenster plus der Lauf vom
     * 1. Juli. Nicht der Testlauf und nicht der, der nie angefangen hat.
     */
    #[Test]
    public function a_run_that_never_started_is_in_no_period_not_even_all_time(): void
    {
        $this->fixture();

        $alleZeit = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(6, (new Runs)->value($alleZeit));
        $this->assertSame(['2026-07' => 1, '2026-08' => 5], (new Runs)->series($alleZeit));

        $this->assertSame(8, AutomationRun::query()->count(), 'die Tabelle haelt acht Zeilen');
        $this->assertSame(1, AutomationRun::query()->whereNull('started_at')->count(), 'eine davon hat nie angefangen');
        $this->assertSame(1, AutomationRun::query()->where('is_test', true)->count(), 'und eine war ein Test');
    }

    /**
     * Ein Lauf mit einem Start in der Zukunft ist kein gelaufener Lauf.
     *
     * Das Gegenstueck zum Test darueber, und dieselbe Luecke von der anderen
     * Seite: „gesamter Zeitraum" hat auch keine OBERE Grenze. Ohne die Klammer
     * ueber `untilNow()` meldete die Kachel dort alles Geplante, als waere es
     * geschehen — und ausgerechnet in dem Bereich, in dem niemand nachrechnet.
     *
     * Die Zeile hier schreibt der Betrieb heute nicht; `started_at` setzt die
     * Maschine, wenn ein Lauf beginnt. Das Schema erzwingt das aber nicht, und
     * die Tabelle nebenan (`automation_scheduled_jobs.due_at`) haelt genau das,
     * was noch kommt. Der Test haelt die Klammer fest, damit sie niemand als
     * ueberfluessig wieder herausnimmt.
     */
    #[Test]
    public function a_run_dated_in_the_future_is_not_history(): void
    {
        $this->fixture();

        $this->lauf([
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2027-01-04 09:00:00',
            'duration_ms' => 1,
        ]);

        $alleZeit = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(6, (new Runs)->value($alleZeit), 'unveraendert: der geplante Lauf ist nicht gelaufen');
        $this->assertArrayNotHasKey('2027-01', (new Runs)->series($alleZeit));

        // Und der Median bleibt der der wirklich gelaufenen: 1 ms waere sonst
        // der kuerzeste Wert und wuerde ihn nach unten ziehen.
        $this->assertSame(2.0, (new DurationP50)->value($this->frage()));
    }

    /**
     * Die Zeitzone der Anwendung verschiebt die Fenstergrenze nicht.
     *
     * Insights baut seinen Zeitraum aus `Carbon::now()`, also aus der Zeit der
     * Anwendung. Dieses Addon schreibt seine Zeitstempel mit `now()` durch
     * Eloquent, ebenfalls in Anwendungszeit — beide Seiten sind naiv lokal, und
     * damit stimmt der Vergleich. Ein Addon, das UTC schriebe, waere auf einer
     * Installation in Chicago um fuenf Stunden versetzt, und der Fehler zeigte
     * sich nur an den Raendern: ein Lauf um 23:30 fiele aus dem Tag heraus.
     *
     * Genau dieser Rand wird hier geprueft.
     */
    #[Test]
    public function the_window_holds_under_a_non_utc_application_timezone(): void
    {
        $vorher = date_default_timezone_get();

        config()->set('app.timezone', 'America/Chicago');
        date_default_timezone_set('America/Chicago');

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-20 23:30:00'));

            // Wie der Betrieb es schreibt: `started_at` aus `now()`.
            $this->lauf(['status' => AutomationRun::STATUS_SUCCESS, 'started_at' => now(), 'duration_ms' => 1000]);

            $frage = new MetricQuery(Period::fromPreset('7d'), MetricQuery::BUCKET_DAY);

            $this->assertSame(1, (new Runs)->value($frage), 'ein Lauf um 23:30 gehoert in den heutigen Tag');
            $this->assertSame(['2026-08-20' => 1], (new Runs)->series($frage));
        } finally {
            date_default_timezone_set($vorher);
            config()->set('app.timezone', $vorher);
        }
    }

    // -- Nichts zu messen ---------------------------------------------------

    /**
     * Keine Tabellen, keine Antwort — und keine Null.
     *
     * „Nichts zu messen" und „nichts gemessen" sind verschiedene Aussagen, und
     * eine Null fuer die erste ist die stille Sorte falsch: sie setzt eine
     * selbstbewusste 0 auf einen Schirm einer Installation, die dieses Addon gar
     * nicht migriert hat.
     */
    #[Test]
    public function a_metric_cannot_answer_without_its_table(): void
    {
        $this->assertTrue((new Runs)->available());
        $this->assertTrue((new OptOuts)->available());

        // Eine zweite, leere Datenbank statt eines Drops in dieser. Ein Drop
        // liesse die Suite ihre eigenen Migrationen nicht mehr zuruecknehmen,
        // und ein Test, der das Aufraeumen seiner Nachbarn kaputtmacht, meldet
        // danach ueberall den falschen Fehler.
        config()->set('database.connections.ohne_automationen', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_automationen');
        DB::setDefaultConnection('ohne_automationen');

        try {
            foreach ([Runs::class, Failures::class, SuccessRate::class, DurationP50::class, OptOuts::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' antwortete ohne seine Tabelle.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' lieferte einen Wert ohne seine Tabelle.');
                $this->assertSame([], $metrik->series($this->frage()));
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    // -- Quoten ohne Nenner -------------------------------------------------

    /**
     * Eine Quote ohne Nenner ist `null`, nie null Prozent.
     *
     * „0 %" waere eine Aussage ueber Laeufe, die es nicht gab, und stuende auf
     * dem Schirm direkt neben einer Laufzahl von null, die ihr widerspricht.
     */
    #[Test]
    public function a_rate_without_a_denominator_has_no_answer(): void
    {
        $this->fixture();

        $this->assertNull((new SuccessRate)->value($this->stillesFenster()));
        $this->assertNull((new DurationP50)->value($this->stillesFenster()));
        $this->assertSame(0, (new Runs)->value($this->stillesFenster()), 'gezaehlt wird trotzdem, und zwar null');
    }

    /**
     * Auch ein Fenster mit Laeufen, aber ohne Urteil, hat keine Quote.
     *
     * Der Fall, der die Wahl des Nenners traegt: alles wartet noch. „0 %" hiesse
     * hier „nichts funktioniert", und funktioniert hat bisher schlicht nichts
     * und nichts nicht.
     */
    #[Test]
    public function runs_that_are_all_still_waiting_produce_no_rate(): void
    {
        $this->lauf([
            'status' => AutomationRun::STATUS_WAITING,
            'started_at' => '2026-08-14 10:00:00',
            'duration_ms' => null,
        ]);

        $this->assertSame(1, (new Runs)->value($this->frage()));
        $this->assertNull((new SuccessRate)->value($this->frage()));
    }

    /**
     * Ein Eimer ohne Nenner bleibt `null` und wird nicht ausgelassen.
     *
     * Der Unterschied ist im Vertrag festgeschrieben und hier der ganze Punkt:
     * ausgelassene Eimer fuellt das Analytics-Addon mit einer Null auf, und eine
     * Null ist bei einer Quote die Behauptung „an diesem Tag hat nichts
     * funktioniert". Am 19.08. lief nur ein Lauf, den ein Stopp-Knoten beendet
     * hat — kein Urteil, also keine Saeule statt einer leeren.
     */
    #[Test]
    public function a_bucket_without_a_denominator_stays_null(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08-15' => 100.0, '2026-08-18' => 0.0, '2026-08-19' => null],
            (new SuccessRate)->series($this->frage()),
        );
    }

    // -- Millisekunden werden zu Sekunden -----------------------------------

    /**
     * Die Spalte ist `duration_ms`, die Einheit sind Sekunden.
     *
     * Und der Median ist immer ein Lauf, den es gab: bei zwei Werten der
     * untere, nie ihr Mittel. Bei 1000 und 3000 also 1,0 und nicht 2,0.
     */
    #[Test]
    public function milliseconds_become_seconds_and_the_median_is_a_real_run(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08-15' => 1.0, '2026-08-18' => 5.0, '2026-08-19' => 2.0],
            (new DurationP50)->series($this->frage()),
            'je Tag der mittlere Lauf, in Sekunden',
        );

        // Ungerade Anzahl: der echte mittlere Wert.
        $this->lauf(['status' => AutomationRun::STATUS_SUCCESS, 'started_at' => '2026-08-15 20:00:00', 'duration_ms' => 250]);

        $this->assertSame(
            1.0,
            (new DurationP50)->series($this->frage())['2026-08-15'],
            '250, 1000, 3000 ms -> 1000 ms',
        );

        // Und eine halbe Sekunde bleibt eine halbe Sekunde. Auf ganze Sekunden
        // abzuschneiden waere fuer jeden kurzen Ablauf eine Null.
        $this->assertSame(0.3, (new DurationP50)->value($this->kurzeLaufzeit()));
    }

    protected function kurzeLaufzeit(): MetricQuery
    {
        $this->lauf(['status' => AutomationRun::STATUS_SUCCESS, 'started_at' => '2026-08-02 10:00:00', 'duration_ms' => 250]);
        $this->lauf(['status' => AutomationRun::STATUS_SUCCESS, 'started_at' => '2026-08-02 11:00:00', 'duration_ms' => 900]);

        return $this->stillesFenster();
    }

    // -- Die Aufteilungen ---------------------------------------------------

    /**
     * Eine Aufteilung traegt Namen, keine uuids.
     *
     * Eine uuid auf einer Kachel ist eine Zeile, die niemand liest, und die
     * Aufteilung nach Automation ist die einzige Frage, fuer die jemand sie
     * ueberhaupt aufmacht: welche Serie macht die Arbeit, welche macht Aerger.
     */
    #[Test]
    public function a_split_by_automation_carries_the_name(): void
    {
        $this->fixture();

        $zeilen = (new Runs)->breakdown($this->frage(), 'automation');

        $this->assertSame(['uuid-willkommen', 'uuid-nachfass'], array_column($zeilen, 'key'), 'groesste zuerst');
        $this->assertSame(['Willkommensserie', 'Nachfassen'], array_column($zeilen, 'label'));
        $this->assertSame([3, 2], array_column($zeilen, 'value'));

        // Und die Aufteilung addiert sich zu der Zahl, die sie aufteilt.
        $this->assertSame(5, array_sum(array_column($zeilen, 'value')));
    }

    /** Ein Status wird uebersetzt, ein fremder Ausloeser bleibt sein Handle. */
    #[Test]
    public function statuses_are_translated_and_trigger_handles_are_not(): void
    {
        $this->fixture();
        $frage = $this->frage();

        // Nach Schluessel sortiert verglichen, nicht nach Reihenfolge: drei
        // der vier Zustaende haben denselben Wert, und in welcher Reihenfolge
        // eine Datenbank Gleichstaende ausgibt, ist ihre Sache. Die
        // Groesse-zuerst-Regel ist eine Zeile tiefer geprueft, wo sie etwas
        // aussagt.
        $nachStatus = $this->keyed((new Runs)->breakdown($frage, 'status'));
        ksort($nachStatus);

        $this->assertSame(
            [
                AutomationRun::STATUS_FAILED => 1,
                AutomationRun::STATUS_STOPPED => 1,
                AutomationRun::STATUS_SUCCESS => 2,
                AutomationRun::STATUS_WAITING => 1,
            ],
            $nachStatus,
        );

        $zeilen = (new Runs)->breakdown($frage, 'status');
        $this->assertSame(__('statamic-automations::insights.status.success'), $zeilen[0]['label']);

        // Ausloeser: das Handle, das auch im Editor am Knoten steht.
        $ausloeser = (new Runs)->breakdown($frage, 'trigger');

        $this->assertSame(3, $this->keyed($ausloeser)['entry.published']);
        $this->assertSame('entry.published', $ausloeser[0]['label']);
    }

    /**
     * Ein Lauf ohne Ausloeser ist eine Zeile mit dem Schluessel `null`.
     *
     * Eine Auswertung, die Zeilen still weglaesst, ist die am schwersten zu
     * bemerkende Sorte falsch: die Spalten addieren sich untereinander weiter,
     * und nur die Summe stimmt nicht — und die rechnet niemand nach.
     */
    #[Test]
    public function a_run_without_a_trigger_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new Runs)->breakdown($this->frage(), 'trigger');

        $ohne = array_values(array_filter($zeilen, fn (array $zeile) => $zeile['key'] === null));

        $this->assertCount(1, $ohne);
        $this->assertSame(1, $ohne[0]['value']);
        $this->assertSame(__('statamic-automations::insights.no_trigger'), $ohne[0]['label']);

        $this->assertSame(5, array_sum(array_column($zeilen, 'value')), 'die Aufteilung addiert sich zur Laufzahl');
    }

    /** Fehlschlaege teilen sich nach Automation, nicht nach ihrem einen Status. */
    #[Test]
    public function failures_split_by_automation_and_ignore_an_unknown_dimension(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            ['uuid-willkommen' => 1],
            $this->keyed((new Failures)->breakdown($frage, 'automation')),
        );

        $this->assertSame([], (new Failures)->breakdown($frage, 'status'), 'ein Status waere hier eine Konstante');
        $this->assertSame([], (new Runs)->breakdown($frage, 'weather'));

        $this->assertSame(['status', 'trigger', 'automation'], array_keys((new Runs)->breakdowns()));
        $this->assertSame(['automation', 'trigger'], array_keys((new Failures)->breakdowns()));
    }

    /** Groesste zuerst, und nie mehr als gefragt. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Runs)->breakdown($this->frage(), 'automation', 1);

        $this->assertCount(1, $zeilen);
        $this->assertSame('uuid-willkommen', $zeilen[0]['key']);
    }

    /**
     * Eine Automation, die es nur als Datei gibt, behaelt trotzdem einen Namen.
     *
     * Im Flat-File-Betrieb stehen Definitionen in YAML und nur die Laeufe in der
     * Datenbank. Der Weg ueber `automations` findet dann nichts, und ohne den
     * zweiten Weg ueber das Repository stuende eine uuid auf dem Schirm.
     */
    #[Test]
    public function an_automation_without_a_database_row_falls_back_to_its_uuid(): void
    {
        $this->lauf([
            'automation_uuid' => 'uuid-nur-als-datei',
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2026-08-14 10:00:00',
        ]);

        $zeilen = (new Runs)->breakdown($this->frage(), 'automation');

        $this->assertSame('uuid-nur-als-datei', $zeilen[0]['key']);
        $this->assertSame('uuid-nur-als-datei', $zeilen[0]['label'], 'lieber das Handle als gar keine Zeile');
    }

    // -- Eine Marke sieht ihre eigenen Zahlen -------------------------------

    /**
     * Im Mehrmarkenbetrieb zaehlt eine Kachel nur die Marke, die gerade gilt.
     *
     * `TableMetric` liest ueber den Query-Builder, an Eloquent und damit an
     * `BrandScope` vorbei. Ohne die nachgebaute Absicherung in
     * {@see AutomationMetric::brandScoped()}
     * summierte die Kachel ueber alle Marken, waehrend die Liste daneben eine
     * zeigt — zwei Zahlen auf einem Schirm, von denen eine luegt, und nichts
     * sagt welche.
     */
    #[Test]
    public function a_figure_counts_only_the_brand_that_is_current(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $a = DB::table('brands')->insertGetId([
            'handle' => 'marke-a', 'name' => 'Marke A', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $b = DB::table('brands')->insertGetId([
            'handle' => 'marke-b', 'name' => 'Marke B', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        BrandContext::runFor($a, fn () => $this->lauf([
            'status' => AutomationRun::STATUS_SUCCESS,
            'started_at' => '2026-08-14 10:00:00',
            'duration_ms' => 1000,
        ]));

        BrandContext::runFor($b, function () {
            $this->lauf(['status' => AutomationRun::STATUS_SUCCESS, 'started_at' => '2026-08-14 11:00:00', 'duration_ms' => 4000]);
            $this->lauf(['status' => AutomationRun::STATUS_FAILED, 'started_at' => '2026-08-14 12:00:00', 'duration_ms' => 4000]);
        });

        BrandContext::setCurrent($a);
        $this->assertSame(1, (new Runs)->value($this->frage()));
        $this->assertSame(0, (new Failures)->value($this->frage()));
        $this->assertSame(100.0, (new SuccessRate)->value($this->frage()));
        $this->assertSame(1.0, (new DurationP50)->value($this->frage()));

        BrandContext::setCurrent($b);
        $this->assertSame(2, (new Runs)->value($this->frage()));
        $this->assertSame(1, (new Failures)->value($this->frage()));
        $this->assertSame(50.0, (new SuccessRate)->value($this->frage()));
    }

    /**
     * Auch die Ausstiege liegen in ihrer eigenen Marke.
     *
     * `automation_opt_outs` ist die zweite Tabelle dieses Ordners, und sie hat
     * die Verengung frueher ueber eine eigene `inPeriod()`-Ueberschreibung
     * bekommen. Seit die Marke zentral an {@see AutomationMetric::brandColumn()}
     * haengt, gibt es diese Ueberschreibung nicht mehr — also wird hier
     * geprueft, dass die Verengung dabei nicht verloren gegangen ist.
     */
    #[Test]
    public function the_opt_outs_count_only_the_brand_that_is_current(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $a = DB::table('brands')->insertGetId([
            'handle' => 'marke-a', 'name' => 'Marke A', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $b = DB::table('brands')->insertGetId([
            'handle' => 'marke-b', 'name' => 'Marke B', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->optOut('uuid-willkommen', 'anna@example.com', '2026-08-16 08:00:00', $a);
        $this->optOut('uuid-willkommen', 'bruno@example.com', '2026-08-17 08:00:00', $b);
        $this->optOut('uuid-willkommen', 'clara@example.com', '2026-08-18 08:00:00', $b);

        BrandContext::setCurrent($a);
        $this->assertSame(1, (new OptOuts)->value($this->frage()));

        BrandContext::setCurrent($b);
        $this->assertSame(2, (new OptOuts)->value($this->frage()));
    }

    /**
     * Ohne aufgeloeste Marke wird die Kachel zu einer Null, nicht zu einer
     * Luecke.
     *
     * `available()` beantwortet, ob es die Sache gibt — Tabelle da, Funktion an,
     * Geschwister installiert. Eine Marke, die niemand gewaehlt hat, ist nichts
     * davon. Eine Null kann ein Leser verstehen; eine verschwundene Kachel kann
     * er nicht bemerken. Die Zeilen werden trotzdem verweigert (`fail closed`),
     * also steht auf dem Schirm eine Null und keine Summe ueber alle Marken.
     */
    #[Test]
    public function an_unresolved_brand_reads_nought_and_stays_on_the_screen(): void
    {
        $this->fixture();

        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
        BrandContext::setCurrent(null);

        foreach ([new Runs, new Failures, new SuccessRate, new DurationP50, new OptOuts] as $kennzahl) {
            $this->assertTrue(
                $kennzahl->available(),
                $kennzahl->handle().' ist von der Marke abhaengig geworden, statt von seiner Tabelle',
            );
        }

        $this->assertSame(0, (new Runs)->value($this->frage()));
        $this->assertSame(0, (new Failures)->value($this->frage()));
        $this->assertSame(0, (new OptOuts)->value($this->frage()));
        $this->assertSame([], (new Runs)->series($this->frage()));

        // Keine Zeilen heisst bei einer Quote weiterhin keine Antwort, und das
        // ist eine Aussage ueber den Nenner und keine ueber die Marke.
        $this->assertNull((new SuccessRate)->value($this->frage()));
        $this->assertNull((new DurationP50)->value($this->frage()));

        // Wo die Installation die andere Antwort vorzieht, liest die Kachel
        // ueber die Marken hinweg — dasselbe, was `BrandScope` mit
        // `fail_mode: open` tut.
        config()->set('brand-context.fail_mode', 'open');
        app('brand-context')->forget();
        BrandContext::setCurrent(null);

        $this->assertSame(5, (new Runs)->value($this->frage()));
    }
}
