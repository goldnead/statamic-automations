<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Integrations\CalCom\CalComEvents;
use Goldnead\StatamicAutomations\Integrations\CalCom\CalComSignature;
use Goldnead\StatamicAutomations\Integrations\CalCom\Triggers as CalT;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Die fuenf cal.com-Auslöser, und ob ein Folgeknoten mit ihnen arbeiten kann.
 *
 * Die Nutzlasten unter tests/Fixtures/cal-com/ sind aus cal.coms Dokumentation
 * uebernommen und nicht ausgedacht. Das ist der Grund, warum diese Datei
 * ueberhaupt etwas beweist: ein Flattener, der gegen eine selbstgebaute
 * Nutzlast getestet wird, bestaetigt nur die eigene Annahme und faellt beim
 * ersten echten Webhook um.
 */
class CalComTriggersTest extends TestCase
{
    private const SECRET = 'ein-webhook-secret';

    private const URL = '/!/automations/cal-com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('automations.integrations.cal_com.secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Die Uhr steht auf dem Tag der Fixtures. Der Empfaenger verwirft einen
        // Umschlag, der aelter als `max_age_minutes` ist; ohne feste Uhr wuerden
        // diese Tests eines Tages von selbst rot, ohne dass sich am Code etwas
        // geaendert haette.
        $this->travelTo(new \DateTimeImmutable('2026-08-29T13:00:00+00:00'));
    }

    // --- die Karte ----------------------------------------------------------

    public function test_the_five_events_map_to_the_documented_handles(): void
    {
        // Ein Tippfehler hier ist still: cal.com liefert, nichts passt, der
        // Ablauf laeuft einfach nie. Und ein Handle laesst sich spaeter nicht
        // mehr aendern, ohne gespeicherte Ablaeufe zu zerreissen.
        $this->assertSame([
            'BOOKING_CREATED' => 'cal_com.booking_created',
            'BOOKING_CANCELLED' => 'cal_com.booking_cancelled',
            'BOOKING_RESCHEDULED' => 'cal_com.booking_rescheduled',
            'BOOKING_REQUESTED' => 'cal_com.booking_requested',
            'BOOKING_REJECTED' => 'cal_com.booking_rejected',
        ], CalComEvents::TRIGGERS);
    }

    public function test_every_handle_in_the_map_has_a_registered_trigger(): void
    {
        $nodes = app(NodeRegistry::class);

        foreach (CalComEvents::TRIGGERS as $handle) {
            $this->assertTrue($nodes->has($handle), "Der Auslöser {$handle} steht nicht in der Knotenbibliothek.");
        }
    }

    public function test_an_event_this_addon_has_no_trigger_for_maps_to_nothing(): void
    {
        // cal.coms Liste bewegt sich. Ein unbekannter Name muss ins Leere
        // laufen und darf nicht zufaellig einen Auslöser treffen.
        $this->assertNull(CalComEvents::handleFor('MEETING_ENDED'));
        $this->assertNull(CalComEvents::handleFor('RECORDING_READY'));
        $this->assertNull(CalComEvents::handleFor(''));
    }

    // --- jeder Auslöser nur bei seinem eigenen Ereignis ----------------------

    public function test_each_trigger_fires_only_on_its_own_event(): void
    {
        foreach ($this->triggersByEvent() as $event => $class) {
            $trigger = new $class;

            foreach (array_keys(CalComEvents::TRIGGERS) as $other) {
                $payload = ['triggerEvent' => $other, 'payload' => ['uid' => 'u']];

                $this->assertSame(
                    $event === $other,
                    $trigger->matches($payload, []),
                    $class." hat auf {$other} falsch geantwortet."
                );
            }
        }
    }

    public function test_a_trigger_filters_by_event_type_slug(): void
    {
        // Ein Betrieb fuehrt mehrere Terminarten. Ohne diesen Filter bekommt
        // jemand, der ein kostenloses Erstgespraech gebucht hat, die Mail zur
        // bezahlten Stunde.
        $trigger = new CalT\BookingCreatedTrigger;
        $event = $this->fixture('booking-created');

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['event_type_slug' => 'erstgespraech']));
        $this->assertFalse($trigger->matches($event, ['event_type_slug' => 'stimmbildung-60']));

        // Gefiltert wird ueber den Slug, nicht ueber den Titel. Wer den Titel
        // eintraegt, soll nichts treffen statt still das Falsche.
        $this->assertFalse($trigger->matches($event, ['event_type_slug' => 'Erstgespraech']));

        // Zweite Achse: die Nummer. Sie ueberlebt eine Umbenennung des Slugs,
        // die bei cal.com die Buchungs-URL huebscher macht und einen
        // Slug-Filter still ausfallen liesse.
        $this->assertTrue($trigger->matches($event, ['event_type_id' => '123']));
        $this->assertTrue($trigger->matches($event, ['event_type_id' => 123]));
        $this->assertFalse($trigger->matches($event, ['event_type_id' => '456']));

        // Beide gesetzt heisst: beide muessen passen.
        $this->assertTrue($trigger->matches($event, ['event_type_slug' => 'erstgespraech', 'event_type_id' => '123']));
        $this->assertFalse($trigger->matches($event, ['event_type_slug' => 'erstgespraech', 'event_type_id' => '456']));
    }

    public function test_a_filter_on_a_payload_that_has_no_event_type_matches_nothing(): void
    {
        // Absicht, und die Richtung ist die vorsichtige: lieber nichts tun als
        // die falsche Mail schicken. Ein gesetzter Filter, der auf eine
        // Nutzlast ohne Terminart trifft, hat keine Grundlage fuer ein Ja.
        $trigger = new CalT\BookingCreatedTrigger;
        $event = ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['uid' => 'u']];

        $this->assertTrue($trigger->matches($event, []));
        $this->assertFalse($trigger->matches($event, ['event_type_slug' => 'erstgespraech']));
        $this->assertFalse($trigger->matches($event, ['event_type_id' => '123']));
    }

    // --- was der Auslöser liefert -------------------------------------------

    public function test_a_created_booking_arrives_flat_and_complete(): void
    {
        // Der Kern der Sache: kann ein Folgeknoten damit arbeiten, ohne in der
        // Rohnutzlast zu graben? Jedes Feld hier ist eins, das eine Mail oder
        // eine Bedingung wirklich braucht.
        $context = (new CalT\BookingCreatedTrigger)
            ->buildContext($this->fixture('booking-created'), [])
            ->all();

        $booking = $context['booking'];

        $this->assertSame('aWtGKzZ1MnNkQjZ5', $booking['uid']);
        $this->assertSame(100, $booking['id']);
        $this->assertSame('Erstgespraech zwischen Adrian Goldner und Nina Weber', $booking['title']);
        $this->assertSame('ACCEPTED', $booking['status']);
        $this->assertSame('2026-09-03T10:00:00+00:00', $booking['starts_at']);
        $this->assertSame('2026-09-03T10:30:00+00:00', $booking['ends_at']);
        $this->assertSame(30, $booking['duration_minutes']);

        // Slug und Titel der Terminart sind zwei verschiedene Felder bei
        // cal.com und muessen zwei verschiedene bleiben: das eine filtert, das
        // andere steht in der Mail.
        $this->assertSame('erstgespraech', $booking['event_type_slug']);
        $this->assertSame('Erstgespraech', $booking['event_type_title']);

        $this->assertSame('https://app.cal.com/video/aWtGKzZ1MnNkQjZ5', $booking['meeting_url']);

        // Der Preis, wie cal.com ihn fuehrt: in der kleinsten Waehrungseinheit.
        // Das Erstgespraech kostet nichts, deshalb 0 und nicht null.
        $this->assertSame(0, $booking['price_cent']);
        $this->assertSame('eur', $booking['currency']);

        // Was der Bucher ins Formular getippt hat. Ohne dieses Feld waere die
        // Vorbereitungsmail leer und niemandem fiele auf, warum.
        $this->assertSame('Ich moechte vor allem an der Hoehe arbeiten.', $booking['notes']);

        // Eigene Buchungsfragen, unter ihrem Feldnamen. Sonst nur ueber die
        // Rohnutzlast erreichbar.
        $this->assertSame('Kammerchor Bonn', $booking['answers']['chor']);

        // Eine Liste als Antwort wird eine Zeile statt "Array".
        $this->assertSame('chorvorstand@example.com', $booking['answers']['guests']);

        // Der Erste ist der, der gebucht hat.
        $this->assertSame('Nina Weber', $booking['attendee']['name']);
        $this->assertSame('Nina', $booking['attendee']['first_name']);
        $this->assertSame('nina.weber@example.com', $booking['attendee']['email']);
        $this->assertSame('Europe/Vienna', $booking['attendee']['timezone']);

        // `language` ist bei cal.com ein Objekt. Hier muss eine Zeichenkette
        // ankommen, sonst steht in der Mail "Array".
        $this->assertSame('de', $booking['attendee']['language']);

        // Die Telefonnummer steht bei cal.com nicht am Teilnehmer, sondern in
        // der Antwort auf das Formularfeld. Ohne den Rueckgriff waere
        // `attendee.phone` ein Feld, das der Editor anbietet und das nie etwas
        // traegt.
        $this->assertSame('+49 171 1234567', $booking['attendee']['phone']);

        $this->assertCount(2, $booking['attendees']);
        $this->assertSame('chorvorstand@example.com', $booking['attendees'][1]['email']);
        $this->assertSame(2, $booking['attendees_count']);

        // Fuer das `to`-Feld der Mail-Aktion, das ein einzelnes Textfeld ist.
        $this->assertSame(
            'nina.weber@example.com, chorvorstand@example.com',
            $booking['attendee_emails']
        );

        $this->assertSame('Adrian Goldner', $booking['organizer']['name']);
        $this->assertSame('info@adriangoldner.com', $booking['organizer']['email']);
        $this->assertSame('Europe/Berlin', $booking['organizer']['timezone']);

        // Nichts abgesagt, nichts abgelehnt, nichts verlegt.
        $this->assertNull($booking['cancellation_reason']);
        $this->assertNull($booking['rejection_reason']);
        $this->assertNull($booking['rescheduled_from_uid']);

        $this->assertSame('BOOKING_CREATED', $context['cal_com']['trigger_event']);
        $this->assertSame('2026-08-29T08:00:00+00:00', $context['cal_com']['received_at']);
    }

    public function test_a_cancellation_carries_its_reason(): void
    {
        $booking = (new CalT\BookingCancelledTrigger)
            ->buildContext($this->fixture('booking-cancelled'), [])
            ->all()['booking'];

        $this->assertSame('CANCELLED', $booking['status']);
        $this->assertSame('Bin an dem Tag doch auf Probenfahrt', $booking['cancellation_reason']);

        // Bei einer Absage fehlt `metadata` in cal.coms Nutzlast ganz. Das darf
        // kein Fehler sein, sondern ein leeres Feld.
        $this->assertNull($booking['meeting_url']);

        // Und hier der eigentliche Punkt der Vereinheitlichung: cal.com
        // schickt denselben Zeitpunkt bei der Anlage als `...Z` und bei der
        // Absage mit Offset. Beim Auslöser kommt beide Male dasselbe an. Sonst
        // faende eine Bedingung "starts_at ist gleich ..." den einen Termin und
        // den anderen nicht.
        $this->assertSame('2026-09-03T10:00:00+00:00', $booking['starts_at']);
    }

    public function test_a_rejection_carries_its_own_reason_and_not_the_cancellation_one(): void
    {
        $booking = (new CalT\BookingRejectedTrigger)
            ->buildContext($this->fixture('booking-rejected'), [])
            ->all()['booking'];

        $this->assertSame('REJECTED', $booking['status']);
        $this->assertSame('In der Woche bin ich auf Chorfreizeit', $booking['rejection_reason']);
        $this->assertNull($booking['cancellation_reason']);
    }

    public function test_a_request_is_pending_and_not_yet_a_booking(): void
    {
        $booking = (new CalT\BookingRequestedTrigger)
            ->buildContext($this->fixture('booking-requested'), [])
            ->all()['booking'];

        $this->assertSame('PENDING', $booking['status']);
        $this->assertSame('stimmbildung-60', $booking['event_type_slug']);
    }

    public function test_a_reschedule_separates_the_new_booking_from_the_old_one(): void
    {
        // cal.com verlegt nicht, es ersetzt: neue Buchung, neue uid. Wer den
        // alten Termin nachhaelt, findet ihn nur ueber diese drei Felder.
        $booking = (new CalT\BookingRescheduledTrigger)
            ->buildContext($this->fixture('booking-rescheduled'), [])
            ->all()['booking'];

        $this->assertSame('cGxNRHhTNVJ2VDdo', $booking['uid']);
        $this->assertSame('2026-09-10T14:30:00+00:00', $booking['starts_at']);

        $this->assertSame('aWtGKzZ1MnNkQjZ5', $booking['rescheduled_from_uid']);
        $this->assertSame('2026-09-03T10:00:00+00:00', $booking['rescheduled_from_starts_at']);
        $this->assertSame('2026-09-03T10:30:00+00:00', $booking['rescheduled_from_ends_at']);

        $this->assertSame('Termin kollidiert mit der Chorprobe', $booking['reschedule_reason']);
    }

    // --- die Zusage, die der Editor anzeigt ---------------------------------

    public function test_every_trigger_describes_exactly_what_it_builds(): void
    {
        // Das outputSchema ist das, was der Datenpicker anzeigt. Eine Zusage,
        // die der Auslöser nicht haelt, ist schlimmer als keine: der Editor
        // bietet ein Feld an, das zur Laufzeit ins Leere zeigt.
        foreach ($this->triggersByEvent() as $event => $class) {
            $schema = $class::outputSchema();
            $context = (new $class)->buildContext($this->fixture($this->fixtureFor($event)), [])->all();

            $this->assertSame(['booking', 'cal_com'], array_keys($schema), $class.' beschreibt die falsche Ausgabe.');
            $this->assertSame(array_keys($schema), array_keys($context), $class.' baut einen anderen Kontext als versprochen.');

            $this->assertSame(
                array_keys($schema['booking']),
                array_keys($context['booking']),
                $class.': booking-Block und sein Schema sind auseinandergelaufen.'
            );

            $this->assertSame(
                array_keys($schema['cal_com']),
                array_keys($context['cal_com']),
                $class.': cal_com-Block und sein Schema sind auseinandergelaufen.'
            );

            // Person einmal ausdruecklich, weil sie an drei Stellen steht.
            foreach (['attendee', 'organizer'] as $person) {
                $this->assertSame(
                    array_keys($schema['booking'][$person]),
                    array_keys($context['booking'][$person]),
                    $class.": {$person} und sein Schema sind auseinandergelaufen."
                );
            }
        }
    }

    public function test_the_declared_types_are_the_types_that_arrive(): void
    {
        // Ein Schema, das `integer` verspricht, und ein Kontext, der ein Array
        // liefert, kaemen durch eine reine Schluesselpruefung unbemerkt durch.
        // Der Token-Aufloeser macht aus einem Array JSON, und dann steht
        // `{"x":1}` in der Mail.
        foreach ($this->triggersByEvent() as $event => $class) {
            $schema = $class::outputSchema();
            $context = (new $class)->buildContext($this->fixture($this->fixtureFor($event)), [])->all();

            foreach ($schema['booking'] as $field => $type) {
                $value = $context['booking'][$field];

                if (is_array($type) || $type === 'array') {
                    $this->assertIsArray($value, "{$class}: booking.{$field} ist kein Array.");

                    continue;
                }

                if ($value === null) {
                    continue;
                }

                match ($type) {
                    'string' => $this->assertIsString($value, "{$class}: booking.{$field} ist keine Zeichenkette."),
                    'integer' => $this->assertIsInt($value, "{$class}: booking.{$field} ist keine ganze Zahl."),
                    default => $this->fail("Unbekannter Typ {$type} im Schema von {$class}."),
                };
            }
        }
    }

    public function test_the_fields_a_flow_relies_on_carry_a_value_on_every_event(): void
    {
        // Der Test gegen die zaeheste Fehlerform: ein Feld, das der Editor
        // anbietet, das aber auf keinem echten Ereignis je etwas traegt. Es
        // faellt nicht auf, weil eine leere Zeile in einer Mail kein Fehler
        // ist.
        foreach ($this->triggersByEvent() as $event => $class) {
            $booking = (new $class)->buildContext($this->fixture($this->fixtureFor($event)), [])->all()['booking'];

            foreach ([
                'uid', 'id', 'title', 'status', 'starts_at', 'ends_at', 'duration_minutes',
                'event_type_slug', 'event_type_title', 'event_type_id', 'currency',
                'location', 'attendee_emails',
            ] as $field) {
                $this->assertNotNull($booking[$field], "{$class}: booking.{$field} ist auf einer echten Nutzlast leer.");
            }

            foreach (['name', 'first_name', 'email', 'timezone', 'language'] as $field) {
                $this->assertNotNull($booking['attendee'][$field], "{$class}: attendee.{$field} ist leer.");
                $this->assertNotNull($booking['organizer'][$field], "{$class}: organizer.{$field} ist leer.");
            }
        }
    }

    public function test_a_trigger_invents_nothing_out_of_an_empty_payload(): void
    {
        // Diese Klassen sehen frueher oder spaeter eine Nutzlast, die cal.com so
        // nicht mehr schickt. Dann muessen die Felder leer sein und nicht
        // erfunden — ein erfundener Wert laeuft still in eine Mail.
        foreach ($this->triggersByEvent() as $class) {
            $context = (new $class)->buildContext(['triggerEvent' => 'X', 'payload' => []], [])->all();

            foreach ($this->leaves($context['booking']) as $path => $value) {
                // `attendees_count` ist die eine Ausnahme, und sie ist keine
                // Erfindung: null Teilnehmer sind bei einer leeren Nutzlast die
                // Wahrheit, nicht ein Platzhalter.
                if ($path === 'attendees_count') {
                    $this->assertSame(0, $value);

                    continue;
                }

                $this->assertTrue(
                    $value === null || $value === [],
                    $class." hat fuer {$path} einen Wert erfunden."
                );
            }
        }
    }

    public function test_a_trigger_survives_a_payload_that_is_shaped_wrong(): void
    {
        // Nicht theoretisch: cal.com fuellt `attendees` je nach Ereignis
        // verschieden, `metadata` fehlt mal ganz, und eine Nutzlast-Vorlage im
        // cal.com-Konto kann die Form komplett veraendern. Ein Fehler hier
        // faellt in einem Queue-Worker an, den niemand ansieht.
        $trigger = new CalT\BookingCreatedTrigger;

        foreach ([
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => 'kein array'],
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['attendees' => 'kein array']],
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['attendees' => ['kein objekt']]],
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['organizer' => 'kein objekt']],
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['metadata' => 'kein objekt']],
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['bookingId' => ['x' => 1], 'length' => ['a'], 'eventTypeId' => ['b'], 'price' => ['c']]],
            ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['startTime' => ['x'], 'responses' => 'kein array']],
            ['triggerEvent' => 'BOOKING_CREATED'],
            [],
        ] as $event) {
            $context = $trigger->buildContext($event, [])->all();

            $this->assertIsArray($context['booking']['attendee']);
            $this->assertIsArray($context['booking']['attendees']);
            $this->assertIsArray($context['booking']['answers']);
            $this->assertNull($context['booking']['meeting_url']);

            // Die Zahlenfelder duerfen nie ein Array durchlassen: der
            // Token-Aufloeser macht daraus JSON und schreibt es in die Mail.
            foreach (['id', 'duration_minutes', 'event_type_id', 'price_cent'] as $field) {
                $this->assertTrue(
                    $context['booking'][$field] === null || is_int($context['booking'][$field]),
                    "booking.{$field} hat eine Struktur durchgelassen."
                );
            }

            $this->assertTrue(
                $context['booking']['starts_at'] === null || is_string($context['booking']['starts_at']),
                'booking.starts_at hat eine Struktur durchgelassen.'
            );
        }
    }

    // --- der ganze Weg, vom Webhook bis zum Lauf ----------------------------

    public function test_a_signed_webhook_starts_the_flow_that_waits_for_it(): void
    {
        Queue::fake();

        $created = $this->automationStartingOn('cal_com.booking_created');
        $cancelled = $this->automationStartingOn('cal_com.booking_cancelled');

        $this->send($this->raw('booking-created'))->assertStatus(200);

        $this->assertSame(1, $this->runsFor($created));
        $this->assertSame(0, $this->runsFor($cancelled), 'Der Auslöser fuer Absagen ist auf eine Anlage gelaufen.');
    }

    public function test_the_same_delivery_twice_starts_the_flow_once(): void
    {
        // cal.com stellt erneut zu, wenn eine Antwort ausbleibt. Ohne diese
        // Schranke bekommt dieselbe Person zwei Mails.
        Queue::fake();

        $automation = $this->automationStartingOn('cal_com.booking_created');
        $body = $this->raw('booking-created');

        $this->send($body)->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->send($body)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, $this->runsFor($automation));
    }

    public function test_two_different_events_on_the_same_booking_both_run(): void
    {
        // Die Schranke haengt am Paar (Ereignis, uid) und nicht an der uid
        // allein. Sonst wuerde die Absage derselben Buchung verschluckt.
        Queue::fake();

        $created = $this->automationStartingOn('cal_com.booking_created');
        $cancelled = $this->automationStartingOn('cal_com.booking_cancelled');

        $this->send($this->raw('booking-created'))->assertStatus(200);
        $this->send($this->raw('booking-cancelled'))->assertStatus(200);

        $this->assertSame(1, $this->runsFor($created));
        $this->assertSame(1, $this->runsFor($cancelled));
    }

    public function test_the_flow_gets_the_flattened_booking_and_not_only_the_raw_payload(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('cal_com.booking_created');

        $this->send($this->raw('booking-created'))->assertStatus(200);

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('Nina Weber', data_get($run->context, 'booking.attendee.name'));
        $this->assertSame('erstgespraech', data_get($run->context, 'booking.event_type_slug'));

        // Und die Rohnutzlast liegt daneben, fuer den Fall, den die Auswahl
        // nicht trifft.
        $this->assertSame('aWtGKzZ1MnNkQjZ5', data_get($run->context, 'cal_com.payload.uid'));
    }

    public function test_the_event_type_filter_holds_over_the_whole_path(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('cal_com.booking_created', ['event_type_slug' => 'stimmbildung-60']);

        $this->send($this->raw('booking-created'))->assertStatus(200);

        $this->assertSame(0, $this->runsFor($automation));
    }

    // --- Helfer -------------------------------------------------------------

    /**
     * @return array<string, class-string>
     */
    private function triggersByEvent(): array
    {
        return [
            'BOOKING_CREATED' => CalT\BookingCreatedTrigger::class,
            'BOOKING_CANCELLED' => CalT\BookingCancelledTrigger::class,
            'BOOKING_RESCHEDULED' => CalT\BookingRescheduledTrigger::class,
            'BOOKING_REQUESTED' => CalT\BookingRequestedTrigger::class,
            'BOOKING_REJECTED' => CalT\BookingRejectedTrigger::class,
        ];
    }

    private function fixtureFor(string $triggerEvent): string
    {
        return str_replace('_', '-', strtolower($triggerEvent));
    }

    private function raw(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/cal-com/'.$name.'.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode($this->raw($name), true, 512, JSON_THROW_ON_ERROR);
    }

    private function send(string $body): TestResponse
    {
        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAL_SIGNATURE_256' => CalComSignature::sign(self::SECRET, $body),
        ], $body);
    }

    private function runsFor(Automation $automation): int
    {
        return AutomationRun::where('automation_id', $automation->id)->count();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function automationStartingOn(string $handle, array $config = []): Automation
    {
        $automation = Automation::create([
            'name' => "Bei {$handle}",
            'handle' => 'bei-'.str_replace('.', '-', $handle).'-'.bin2hex(random_bytes(4)),
            'enabled' => true,
        ]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => $handle,
            'config' => $config,
        ]);

        return $automation->fresh();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function leaves(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== []) {
                $flat += $this->leaves($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
