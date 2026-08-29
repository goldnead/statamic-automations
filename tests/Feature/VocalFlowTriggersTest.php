<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers as VfT;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowEvents;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowSignature;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Die sieben VocalFlow-Auslöser, und ob ein Folgeknoten mit ihnen arbeiten
 * kann.
 *
 * Die Nutzlasten unter tests/Fixtures/vocalflow/ sind aus VocalFlows Quelltext
 * erhoben und nicht ausgedacht. Das ist der Grund, warum diese Datei ueberhaupt
 * etwas beweist: ein Flattener, der gegen eine selbstgebaute Nutzlast getestet
 * wird, bestaetigt nur die eigene Annahme und faellt beim ersten echten Webhook
 * um.
 */
class VocalFlowTriggersTest extends TestCase
{
    private const SECRET = 'ein-webhook-secret';

    private const PUBLICATION_SECRET = 'ein-publikations-token';

    private const URL = '/!/automations/vocalflow';

    private const PUBLISHED_URL = '/!/automations/vocalflow/session-published';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('automations.integrations.vocalflow.secret', self::SECRET);
        $app['config']->set('automations.integrations.vocalflow.publication_secret', self::PUBLICATION_SECRET);
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

    public function test_the_six_events_map_to_the_documented_handles(): void
    {
        // Ein Tippfehler hier ist still: VocalFlow liefert, nichts passt, der
        // Ablauf laeuft einfach nie. Und ein Handle laesst sich spaeter nicht
        // mehr aendern, ohne gespeicherte Ablaeufe zu zerreissen.
        $this->assertSame([
            'session.created' => 'vocalflow.session_created',
            'session.completed' => 'vocalflow.session_completed',
            'task.created' => 'vocalflow.task_created',
            'task.updated' => 'vocalflow.task_updated',
            'task.assigned' => 'vocalflow.task_assigned',
            'task.deleted' => 'vocalflow.task_deleted',
        ], VocalFlowEvents::TRIGGERS);
    }

    /**
     * Die Handle-Regel, festgenagelt.
     *
     * `<Dienstname, klein, Nicht-Alphanumerisches zu Unterstrich>`. Bei
     * `VocalFlow` gibt es nichts zu ersetzen, also `vocalflow` und nicht
     * `vocal_flow` — dieselbe Regel, die aus `statamic-leadhub` ein `leadhub.`
     * macht und nicht `lead_hub.`. Der Test steht hier, weil der Zug in
     * Richtung `vocal_flow` echt ist und ein Handle sich nicht mehr aendern
     * laesst.
     */
    public function test_the_prefix_follows_the_handle_rule_and_is_not_split_at_the_word_boundary(): void
    {
        foreach (VocalFlowEvents::TRIGGERS as $handle) {
            $this->assertStringStartsWith('vocalflow.', $handle);
            $this->assertStringNotContainsString('vocal_flow', $handle);
        }

        $this->assertSame('vocalflow.session_published', VocalFlowEvents::SESSION_PUBLISHED_HANDLE);
    }

    public function test_every_handle_in_the_map_has_a_registered_trigger(): void
    {
        $nodes = app(NodeRegistry::class);

        foreach (VocalFlowEvents::TRIGGERS as $handle) {
            $this->assertTrue($nodes->has($handle), "Der Auslöser {$handle} steht nicht in der Knotenbibliothek.");
        }

        $this->assertTrue($nodes->has(VocalFlowEvents::SESSION_PUBLISHED_HANDLE));
    }

    public function test_an_event_this_addon_has_no_trigger_for_maps_to_nothing(): void
    {
        // VocalFlows Liste bewegt sich, und `session.updated` und
        // `session.deleted` gibt es dort wirklich — sie standen nur nicht in der
        // Liste, gegen die dieser Anschluss gebaut wurde. Ein unbekannter Name
        // muss ins Leere laufen und darf nicht zufaellig einen Auslöser treffen.
        $this->assertNull(VocalFlowEvents::handleFor('session.updated'));
        $this->assertNull(VocalFlowEvents::handleFor('session.deleted'));
        $this->assertNull(VocalFlowEvents::handleFor('user.registered'));
        $this->assertNull(VocalFlowEvents::handleFor(''));

        // Und die veroeffentlichte Session laeuft nicht ueber diese Karte: sie
        // kommt ueber einen anderen Endpunkt und traegt gar kein `event`.
        $this->assertNull(VocalFlowEvents::handleFor('session.published'));
    }

    // --- jeder Auslöser nur bei seinem eigenen Ereignis ----------------------

    public function test_each_trigger_fires_only_on_its_own_event(): void
    {
        $all = array_merge(
            array_keys(VocalFlowEvents::TRIGGERS),
            [VocalFlowEvents::SESSION_PUBLISHED_EVENT]
        );

        foreach ($this->triggersByEvent() as $event => $class) {
            $trigger = new $class;

            foreach ($all as $other) {
                $payload = ['event' => $other, 'data' => []];

                $this->assertSame(
                    $event === $other,
                    $trigger->matches($payload, []),
                    $class." hat auf {$other} falsch geantwortet."
                );
            }
        }
    }

    // --- die Filter ---------------------------------------------------------

    public function test_a_session_trigger_filters_by_session_type(): void
    {
        // Ein Betrieb fuehrt mehrere Sitzungsarten. Ohne diesen Filter bekommt
        // jemand, der nur schnuppern wollte, die Nachbereitung der bezahlten
        // Stunde.
        $trigger = new VfT\SessionCreatedTrigger;
        $event = $this->fixture('session-created');

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['session_type_slug' => 'einzelstunde']));
        $this->assertFalse($trigger->matches($event, ['session_type_slug' => 'schnupperstunde']));

        // Gefiltert wird ueber den Slug, nicht ueber den Namen. Wer den Namen
        // eintraegt, soll nichts treffen statt still das Falsche.
        $this->assertFalse($trigger->matches($event, ['session_type_slug' => 'Einzelstunde']));

        // Zweite Achse: die Kennung. Sie ueberlebt eine Umbenennung, die den
        // Slug aendert und einen Slug-Filter still ausfallen liesse.
        $this->assertTrue($trigger->matches($event, ['session_type_id' => '019bffde-9c10-7246-be91-3eca56c5d7dd']));
        $this->assertFalse($trigger->matches($event, ['session_type_id' => '019bffde-c47d-7067-b20a-1857f2f9b9a7']));

        // Beide gesetzt heisst: beide muessen passen.
        $this->assertTrue($trigger->matches($event, [
            'session_type_slug' => 'einzelstunde',
            'session_type_id' => '019bffde-9c10-7246-be91-3eca56c5d7dd',
        ]));
        $this->assertFalse($trigger->matches($event, [
            'session_type_slug' => 'einzelstunde',
            'session_type_id' => '019bffde-c47d-7067-b20a-1857f2f9b9a7',
        ]));
    }

    public function test_a_session_type_filter_on_a_payload_without_one_matches_nothing(): void
    {
        // Absicht, und die Richtung ist die vorsichtige: lieber nichts tun als
        // die falsche Mail schicken. Ein gesetzter Filter, der auf eine Nutzlast
        // ohne Sitzungsart trifft, hat keine Grundlage fuer ein Ja.
        $trigger = new VfT\SessionCreatedTrigger;
        $event = ['event' => 'session.created', 'data' => ['session' => ['id' => 'x']]];

        $this->assertTrue($trigger->matches($event, []));
        $this->assertFalse($trigger->matches($event, ['session_type_slug' => 'einzelstunde']));
        $this->assertFalse($trigger->matches($event, ['session_type_id' => '019bffde-9c10-7246-be91-3eca56c5d7dd']));
    }

    public function test_a_task_trigger_filters_by_status_and_priority(): void
    {
        $trigger = new VfT\TaskUpdatedTrigger;
        $event = $this->fixture('task-updated');

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['status' => 'completed']));
        $this->assertFalse($trigger->matches($event, ['status' => 'assigned']));

        $this->assertTrue($trigger->matches($event, ['priority' => 'medium']));
        $this->assertFalse($trigger->matches($event, ['priority' => 'high']));

        // Beide gesetzt heisst: beide muessen passen.
        $this->assertTrue($trigger->matches($event, ['status' => 'completed', 'priority' => 'medium']));
        $this->assertFalse($trigger->matches($event, ['status' => 'completed', 'priority' => 'high']));
    }

    /**
     * Die Aufgabenart ist bewusst keine Filterachse.
     *
     * Sie waere die naheliegende, aber VocalFlow legt `type` nur bei
     * `task.assigned` in die Nutzlast. Ein Filter darauf faellt bei
     * `task.created` und `task.updated` still aus: der Ablauf laeuft einfach
     * nie, und niemand sucht danach. Die beiden angebotenen Achsen stehen
     * dagegen in allen drei Nutzlasten.
     */
    public function test_the_offered_task_filters_carry_a_value_on_every_task_payload(): void
    {
        $handles = array_column(VfT\TaskAssignedTrigger::schema(), 'handle');

        $this->assertSame(['status', 'priority'], $handles);
        $this->assertNotContains('type', $handles);

        foreach (['task-created', 'task-updated', 'task-assigned', 'task-deleted'] as $fixture) {
            $task = $this->fixture($fixture)['data']['task'];

            foreach ($handles as $handle) {
                $this->assertArrayHasKey($handle, $task, "{$fixture}: der Filter {$handle} findet in dieser Nutzlast nichts.");
            }
        }
    }

    /**
     * Der Zustandsfilter, ohne den "Session angelegt" nicht benutzbar ist.
     *
     * `session.created` feuert bei VocalFlow an jedem entstehenden Datensatz:
     * ein liegen gelassener Entwurf traegt `draft`, und der Import von
     * Alt-Sitzungen legt sie mit `completed` an. Ein Ablauf "Unterlagen zur
     * Vorbereitung schicken" ohne diesen Filter mailt beim naechsten Import an
     * jeden Studenten einmal pro Altstunde.
     */
    public function test_a_session_trigger_filters_by_status(): void
    {
        $trigger = new VfT\SessionCreatedTrigger;
        $event = $this->fixture('session-created');

        $this->assertTrue($trigger->matches($event, ['status' => 'scheduled']));
        $this->assertFalse($trigger->matches($event, ['status' => 'completed']));

        // Die beiden Faelle, wegen derer es den Filter gibt.
        foreach (['draft', 'completed'] as $status) {
            $imported = $event;
            $imported['data']['session']['status'] = $status;

            $this->assertTrue($trigger->matches($imported, []), 'Ohne Filter laeuft alles.');
            $this->assertFalse(
                $trigger->matches($imported, ['status' => 'scheduled']),
                "Eine Session mit dem Zustand {$status} kam durch einen Filter auf scheduled."
            );
        }
    }

    /**
     * Kein Feld im Schema, das auf keiner echten Nutzlast je etwas traegt.
     *
     * Das ist derselbe Massstab, den `taskFilterSchema()` an die Filter anlegt,
     * nur fuer die Ausgabe. Ein Feld, das der Datenpicker anbietet und das
     * immer leer ankommt, faellt nicht auf: eine leere Zeile in einer Mail ist
     * kein Fehler.
     *
     * Was der Test **nicht** verlangt, ist ein Wert auf jedem Ereignis.
     * `task.type` steht nur bei `task.assigned`, `completion.quality` nur beim
     * Abschluss, und das ist in Ordnung — der Auslöser sagt jeweils, welches
     * Ereignis er bedient.
     */
    public function test_no_advertised_field_is_empty_on_every_single_payload(): void
    {
        $seen = [];

        foreach ($this->triggersByEvent() as $event => $class) {
            $context = (new $class)->buildContext($this->fixture($this->fixtureFor($event)), [])->all();

            foreach ($class::outputSchema() as $block => $fields) {
                if (! is_array($fields)) {
                    continue;
                }

                foreach (array_keys($fields) as $field) {
                    $path = "{$block}.{$field}";
                    $seen[$path] = ($seen[$path] ?? false) || ($context[$block][$field] ?? null) !== null;
                }
            }
        }

        $this->assertSame([], array_keys(array_filter($seen, fn ($carried) => ! $carried)));
    }

    // --- was die Auslöser liefern -------------------------------------------

    public function test_a_created_session_arrives_flat_and_complete(): void
    {
        // Der Kern der Sache: kann ein Folgeknoten damit arbeiten, ohne in der
        // Rohnutzlast zu graben? Jedes Feld hier ist eins, das eine Mail oder
        // eine Bedingung wirklich braucht.
        $context = (new VfT\SessionCreatedTrigger)
            ->buildContext($this->fixture('session-created'), [])
            ->all();

        $session = $context['session'];

        $this->assertSame('019bffde-1a2b-7000-8000-000000000001', $session['id']);
        $this->assertSame('scheduled', $session['status']);
        $this->assertSame('2026-09-03T10:00:00+00:00', $session['scheduled_at']);
        $this->assertSame('2026-08-29T11:59:58+00:00', $session['created_at']);

        $this->assertSame('019bffde-9c10-7246-be91-3eca56c5d7dd', $session['session_type_id']);
        $this->assertSame('Einzelstunde', $session['session_type_name']);
        $this->assertSame('einzelstunde', $session['session_type_slug']);
        $this->assertSame(60, $session['session_type_duration_minutes']);

        // Die beiden Kennungen, die man verwechselt: `id` ist die laufende
        // Nummer des Kontos, `uuid` der Wert, mit dem VocalFlow verknuepft. Sie
        // stehen bewusst an derselben Person und nicht ueber `session` und
        // `student` verteilt, wo sie fast gleich heissen wuerden.
        $this->assertSame(42, $context['student']['id']);
        $this->assertSame('019bffde-57ad-7000-8000-00000000000b', $context['student']['uuid']);
        $this->assertSame('Nina Sömmer', $context['student']['name']);
        $this->assertSame('Nina', $context['student']['first_name']);
        $this->assertSame('nina@example.com', $context['student']['email']);

        $this->assertSame(1, $context['coach']['id']);
        $this->assertSame('019bffde-c0ac-7000-8000-00000000000a', $context['coach']['uuid']);
        $this->assertSame('coach@example.com', $context['coach']['email']);

        // Eine Personen-Kennung hat in `session` nichts mehr zu suchen: dort
        // hiesse sie `student_id` und stuende neben `student.id`, was eine
        // andere Zahl ist.
        $this->assertArrayNotHasKey('student_id', $session);
        $this->assertArrayNotHasKey('coach_id', $session);

        $this->assertSame('session.created', $context['vocalflow']['event']);
        $this->assertSame('2026-08-29T12:00:00+00:00', $context['vocalflow']['received_at']);

        // Und die Rohnutzlast liegt daneben, fuer den Fall, den die Auswahl
        // nicht trifft.
        $this->assertSame('vocalflow', $context['vocalflow']['metadata']['source']);
        $this->assertSame(42, $context['vocalflow']['data']['student']['id']);
    }

    public function test_a_completed_session_carries_the_verdict_that_a_follow_up_hangs_on(): void
    {
        $context = (new VfT\SessionCompletedTrigger)
            ->buildContext($this->fixture('session-completed'), [])
            ->all();

        $this->assertSame('completed', $context['session']['status']);
        $this->assertSame('2026-08-29T11:04:12+00:00', $context['session']['completed_at']);
        $this->assertSame(5, $context['session']['rating']);
        $this->assertSame(64, $context['session']['duration_minutes']);

        $this->assertSame('excellent', $context['completion']['quality']);
        $this->assertSame(2, $context['completion']['tasks_assigned']);

        // Die gute Stunde: empfehlenswert, kein Nachfassen noetig.
        $this->assertFalse($context['completion']['follow_up_required']);
        $this->assertTrue($context['completion']['referral_eligible']);

        // VocalFlow laesst `duration_minutes` der Sitzungsart bei diesem
        // Ereignis weg. Das darf kein Fehler sein, sondern ein leeres Feld.
        $this->assertNull($context['session']['session_type_duration_minutes']);
        $this->assertSame('einzelstunde', $context['session']['session_type_slug']);
    }

    public function test_a_follow_up_and_a_referral_are_two_different_sessions(): void
    {
        // VocalFlow setzt `referral_eligible` nur, wenn die Bewertung
        // mindestens 4 ist **und** kein Nachfassen noetig ist. Die beiden sind
        // also nie gleichzeitig wahr, und genau darauf haengt ein Ablauf: die
        // Nachfassmail und die Empfehlungsfrage sind verschiedene Nachrichten
        // und sollen nicht beide an alle gehen.
        //
        // Der Test steht hier, weil eine Fixture mit beidem auf `true` die
        // Vorlage waere, an der jemand genau das falsch baut — und weil sie in
        // VocalFlow gar nicht entstehen kann.
        $trigger = new VfT\SessionCompletedTrigger;

        $referral = $trigger->buildContext($this->fixture('session-completed'), [])->all()['completion'];
        $followUp = $trigger->buildContext($this->fixture('session-completed-follow-up'), [])->all()['completion'];

        foreach ([$referral, $followUp] as $completion) {
            $this->assertFalse(
                $completion['follow_up_required'] && $completion['referral_eligible'],
                'Nachfassen und Empfehlung koennen bei VocalFlow nicht beide wahr sein.'
            );
        }

        $this->assertTrue($followUp['follow_up_required']);
        $this->assertFalse($followUp['referral_eligible']);
        $this->assertSame('needs_improvement', $followUp['quality']);
    }

    public function test_an_assigned_task_arrives_flat_and_complete(): void
    {
        $context = (new VfT\TaskAssignedTrigger)
            ->buildContext($this->fixture('task-assigned'), [])
            ->all();

        $task = $context['task'];

        $this->assertSame('019bffde-4d5e-7000-8000-000000000002', $task['id']);
        $this->assertSame('Übung im 3/4-Takt', $task['title']);
        $this->assertSame('vocal-exercise', $task['type']);
        $this->assertSame('assigned', $task['status']);
        $this->assertSame('high', $task['priority']);
        $this->assertSame(15, $task['estimated_duration_minutes']);
        $this->assertSame('019bffde-1a2b-7000-8000-000000000001', $task['session_id']);

        $this->assertSame('2026-09-02T00:00:00+00:00', $task['due_date']);

        // Nur `task.assigned` traegt die Kennung des Studenten.
        $this->assertSame('019bffde-57ad-7000-8000-00000000000b', $context['student']['uuid']);

        // Die Session in Kurzform, damit ein Ablauf weiss, woran die Aufgabe
        // haengt.
        $this->assertSame('019bffde-1a2b-7000-8000-000000000001', $context['session']['id']);
        $this->assertSame('completed', $context['session']['status']);
    }

    public function test_a_created_task_has_no_student_uuid_and_says_so_by_being_empty(): void
    {
        // VocalFlow legt `student_id` nur bei `task.assigned` bei. Wichtig ist,
        // dass das Feld leer ist und nicht erfunden, und dass die Adresse
        // trotzdem traegt — sie ist der Wert, ueber den die Partner-API
        // adressiert.
        $context = (new VfT\TaskCreatedTrigger)
            ->buildContext($this->fixture('task-created'), [])
            ->all();

        $this->assertNull($context['student']['uuid']);
        $this->assertSame('nina@example.com', $context['student']['email']);
        $this->assertSame(42, $context['student']['id']);
    }

    public function test_an_updated_task_separates_what_it_was_from_what_it_is(): void
    {
        // Der Ablauf, den man mit diesem Auslöser fast immer baut, ist "die
        // Aufgabe ist jetzt fertig". Dafuer reicht der Zustand allein nicht:
        // der passt auch bei jeder spaeteren Aenderung an einer schon fertigen
        // Aufgabe. Was ihn traegt, ist der Uebergang.
        $context = (new VfT\TaskUpdatedTrigger)
            ->buildContext($this->fixture('task-updated'), [])
            ->all();

        $this->assertSame(['status'], $context['fields']);
        $this->assertSame('assigned', $context['changed_from']['status']);
        $this->assertSame('completed', $context['changed_to']['status']);

        $this->assertSame('completed', $context['task']['status']);
        $this->assertSame('2026-08-29T12:39:58+00:00', $context['task']['updated_at']);
    }

    public function test_an_updated_task_without_a_change_record_stays_empty_rather_than_guessing(): void
    {
        // VocalFlow legt `data.changes` nur bei, wenn es den Zustand davor
        // kennt. Eine Bedingung auf `changed_to.status` muss dann ins Leere
        // laufen statt falsch zu treffen.
        $payload = $this->fixture('task-updated');
        unset($payload['data']['changes']);

        $context = (new VfT\TaskUpdatedTrigger)->buildContext($payload, [])->all();

        $this->assertSame([], $context['fields']);
        $this->assertSame([], $context['changed_from']);
        $this->assertSame([], $context['changed_to']);
    }

    public function test_an_emptied_field_is_told_apart_from_an_untouched_one(): void
    {
        // Wer eine Faelligkeit streicht, schickt `{"to": null}`. Faellt der
        // Schluessel dann aus `changed_to`, sieht das genauso aus wie ein Feld,
        // das gar nicht in der Aenderungsliste stand — und eine Bedingung
        // "Faelligkeit gestrichen" laesst sich nicht mehr bauen.
        $payload = $this->fixture('task-updated');
        $payload['data']['changes'] = ['due_date' => ['from' => '2026-09-05T00:00:00.000000Z', 'to' => null]];

        $context = (new VfT\TaskUpdatedTrigger)->buildContext($payload, [])->all();

        $this->assertSame(['due_date'], $context['fields']);
        $this->assertArrayHasKey('due_date', $context['changed_to']);
        $this->assertSame('', $context['changed_to']['due_date']);
        $this->assertSame('2026-09-05T00:00:00.000000Z', $context['changed_from']['due_date']);
    }

    public function test_a_structured_change_value_becomes_nothing_rather_than_a_plausible_wrong_one(): void
    {
        // Eine Liste zu einer Zeile zusammenzuziehen verliert nichts. Eine
        // Karte dagegen verliert die Schluessel und laesst einen Wert zurueck,
        // der wie ein echter aussieht: aus `['farbe' => 'rot']` wuerde `"rot"`,
        // und eine Bedingung darauf vergleicht gegen etwas, das es nie gab.
        $payload = $this->fixture('task-updated');
        $payload['data']['changes'] = [
            'einstellungen' => ['from' => ['farbe' => 'rot'], 'to' => ['farbe' => 'blau']],
            'tags' => ['from' => ['a', 'b'], 'to' => ['a', 'b', 'c']],
        ];

        $context = (new VfT\TaskUpdatedTrigger)->buildContext($payload, [])->all();

        // Das Feld hat sich geaendert, das steht in `fields`. Nur der Wert ist
        // nicht in Text zu fassen.
        $this->assertSame(['einstellungen', 'tags'], $context['fields']);
        $this->assertSame('', $context['changed_to']['einstellungen']);
        $this->assertSame('a, b, c', $context['changed_to']['tags']);
    }

    public function test_a_condition_can_address_a_single_changed_field(): void
    {
        // Der Datenpicker zeigt `changed_to` als einen Eintrag, weil die
        // Schluessel darin erst die Nutzlast kennt. Zur Laufzeit muss der Pfad
        // trotzdem tragen, sonst waere der Auslöser fuer den Ablauf, wegen dem
        // es ihn gibt, nicht benutzbar.
        $context = (new VfT\TaskUpdatedTrigger)->buildContext($this->fixture('task-updated'), []);

        $this->assertSame('completed', $context->get('changed_to.status'));
        $this->assertSame('assigned', $context->get('changed_from.status'));
        $this->assertNull($context->get('changed_to.priority'));
    }

    public function test_a_bare_date_is_read_as_utc_and_not_as_local_time(): void
    {
        // VocalFlow schickt alle Zeitpunkte mit Zone. Sollte einer doch einmal
        // ohne ankommen, darf die Anwendungs-Zeitzone ihn nicht verschieben:
        // derselbe Wert ergaebe sonst auf einem deutschen Kunden eine andere
        // Uhrzeit als auf einem Server in UTC, und eine Erinnerung "am Tag vor
        // der Faelligkeit" spraenge ueber die Tagesgrenze. In einem Testlauf
        // waere das nie zu sehen, weil Testumgebungen in UTC laufen.
        config()->set('app.timezone', 'Europe/Berlin');
        date_default_timezone_set('Europe/Berlin');

        try {
            $payload = $this->fixture('task-updated');
            $payload['data']['task']['due_date'] = '2026-09-05';

            $context = (new VfT\TaskUpdatedTrigger)->buildContext($payload, [])->all();

            $this->assertSame('2026-09-05T00:00:00+00:00', $context['task']['due_date']);
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_a_name_made_of_spaces_is_no_name(): void
    {
        // Sonst druckt die Anrede "Hallo " und niemandem faellt auf, warum.
        $payload = $this->fixture('session-created');
        $payload['data']['student']['name'] = '   ';

        $context = (new VfT\SessionCreatedTrigger)->buildContext($payload, [])->all();

        $this->assertNull($context['student']['name']);
        $this->assertNull($context['student']['first_name']);
    }

    public function test_a_coach_on_a_task_carries_no_uuid_because_vocalflow_sends_none(): void
    {
        // VocalFlow legt `coach_id` nur an das `session`-Objekt. Auf einer
        // Aufgaben-Nutzlast waere `coach.uuid` deshalb immer leer, und ein
        // Feld, das der Datenpicker anbietet und das nie etwas traegt, ist
        // schlimmer als ein fehlendes.
        foreach (['task-created', 'task-updated', 'task-assigned', 'task-deleted'] as $fixture) {
            $this->assertArrayNotHasKey(
                'coach_id',
                $this->fixture($fixture)['data']['task'],
                "{$fixture}: die Annahme stimmt nicht mehr, VocalFlow schickt jetzt eine Coach-Kennung."
            );
        }

        $context = (new VfT\TaskAssignedTrigger)->buildContext($this->fixture('task-assigned'), [])->all();

        $this->assertArrayNotHasKey('uuid', $context['coach']);
        $this->assertArrayHasKey('uuid', $context['student']);
        $this->assertArrayNotHasKey('uuid', VfT\TaskAssignedTrigger::outputSchema()['coach']);
    }

    public function test_a_published_session_carries_the_two_fields_it_has_and_no_more(): void
    {
        $context = (new VfT\SessionPublishedTrigger)->buildContext([
            'event' => 'session.published',
            'data' => [
                'session' => ['id' => '019bffde-1a2b-7000-8000-000000000001'],
                'student' => ['email' => 'nina@example.com'],
            ],
        ], [])->all();

        $this->assertSame(['session', 'student', 'vocalflow'], array_keys($context));
        $this->assertSame(['id'], array_keys($context['session']));
        $this->assertSame(['email'], array_keys($context['student']));

        $this->assertSame('019bffde-1a2b-7000-8000-000000000001', $context['session']['id']);
        $this->assertSame('nina@example.com', $context['student']['email']);
        $this->assertSame('session.published', $context['vocalflow']['event']);
        $this->assertSame('2026-08-29T13:00:00+00:00', $context['vocalflow']['received_at']);
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

            $this->assertSame(
                array_keys($schema),
                array_keys($context),
                $class.' baut einen anderen Kontext als versprochen.'
            );

            foreach ($schema as $block => $fields) {
                if (! is_array($fields)) {
                    continue;
                }

                $this->assertSame(
                    array_keys($fields),
                    array_keys($context[$block]),
                    $class.": der Block {$block} und sein Schema sind auseinandergelaufen."
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

            foreach ($schema as $block => $fields) {
                if (! is_array($fields)) {
                    $this->assertIsArray($context[$block], "{$class}: {$block} ist kein Array.");

                    continue;
                }

                foreach ($fields as $field => $type) {
                    $value = $context[$block][$field];

                    if (is_array($type) || $type === 'array') {
                        $this->assertIsArray($value, "{$class}: {$block}.{$field} ist kein Array.");

                        continue;
                    }

                    if ($value === null) {
                        continue;
                    }

                    match ($type) {
                        'string' => $this->assertIsString($value, "{$class}: {$block}.{$field} ist keine Zeichenkette."),
                        'integer' => $this->assertIsInt($value, "{$class}: {$block}.{$field} ist keine ganze Zahl."),
                        'boolean' => $this->assertIsBool($value, "{$class}: {$block}.{$field} ist kein Wahrheitswert."),
                        default => $this->fail("Unbekannter Typ {$type} im Schema von {$class}."),
                    };
                }
            }
        }
    }

    public function test_the_fields_a_flow_relies_on_carry_a_value_on_every_event(): void
    {
        // Der Test gegen die zaeheste Fehlerform: ein Feld, das der Editor
        // anbietet, das aber auf keinem echten Ereignis je etwas traegt. Es
        // faellt nicht auf, weil eine leere Zeile in einer Mail kein Fehler ist.
        foreach ($this->triggersByEvent() as $event => $class) {
            $context = (new $class)->buildContext($this->fixture($this->fixtureFor($event)), [])->all();

            foreach (['name', 'first_name', 'email', 'id'] as $field) {
                $this->assertNotNull($context['student'][$field], "{$class}: student.{$field} ist auf einer echten Nutzlast leer.");
                $this->assertNotNull($context['coach'][$field], "{$class}: coach.{$field} ist auf einer echten Nutzlast leer.");
            }

            $entity = str_starts_with($event, 'task.') ? 'task' : 'session';

            foreach (['id', 'status'] as $field) {
                $this->assertNotNull($context[$entity][$field], "{$class}: {$entity}.{$field} ist leer.");
            }

            $this->assertNotNull($context['vocalflow']['event']);
            $this->assertNotNull($context['vocalflow']['received_at']);
        }
    }

    public function test_a_trigger_invents_nothing_out_of_an_empty_payload(): void
    {
        // Diese Klassen sehen frueher oder spaeter eine Nutzlast, die VocalFlow
        // so nicht mehr schickt. Dann muessen die Felder leer sein und nicht
        // erfunden — ein erfundener Wert laeuft still in eine Mail.
        foreach ($this->triggersByEvent() as $event => $class) {
            $context = (new $class)->buildContext(['event' => $event, 'data' => []], [])->all();

            foreach (['session', 'task', 'student', 'coach', 'completion'] as $block) {
                foreach ($context[$block] ?? [] as $field => $value) {
                    $this->assertNull($value, "{$class} hat fuer {$block}.{$field} einen Wert erfunden.");
                }
            }
        }
    }

    public function test_a_trigger_survives_a_payload_that_is_shaped_wrong(): void
    {
        // Nicht theoretisch: VocalFlow fuellt `data` je Ereignis verschieden,
        // einzelne Zweige fehlen ganz, und ein Fehler hier faellt in einem
        // Queue-Worker an, den niemand ansieht.
        foreach ([new VfT\SessionCreatedTrigger, new VfT\TaskUpdatedTrigger] as $trigger) {
            foreach ([
                ['event' => 'x', 'data' => 'kein array'],
                ['event' => 'x', 'data' => ['session' => 'kein objekt', 'task' => 'kein objekt']],
                ['event' => 'x', 'data' => ['student' => 'kein objekt', 'coach' => 'kein objekt']],
                ['event' => 'x', 'data' => ['session' => ['rating' => ['x'], 'duration_minutes' => ['y']]]],
                ['event' => 'x', 'data' => ['task' => ['estimated_duration_minutes' => ['x'], 'due_date' => ['y']]]],
                ['event' => 'x', 'data' => ['changes' => 'kein array']],
                ['event' => 'x', 'data' => ['changes' => ['status' => 'kein objekt']]],
                ['event' => 'x', 'metadata' => 'kein objekt'],
                ['event' => 'x'],
                [],
            ] as $event) {
                $context = $trigger->buildContext($event, [])->all();

                $this->assertIsArray($context['student']);
                $this->assertIsArray($context['coach']);
                $this->assertIsArray($context['vocalflow']['data']);
                $this->assertIsArray($context['vocalflow']['metadata']);

                // Die Zahlenfelder duerfen nie eine Struktur durchlassen: der
                // Token-Aufloeser macht daraus JSON und schreibt es in die Mail.
                foreach ($context as $block) {
                    if (! is_array($block)) {
                        continue;
                    }

                    foreach ($block as $key => $value) {
                        if (in_array($key, ['data', 'metadata', 'fields', 'changed_from', 'changed_to'], true)) {
                            continue;
                        }

                        $this->assertTrue(
                            $value === null || is_scalar($value),
                            "{$key} hat eine Struktur durchgelassen."
                        );
                    }
                }
            }
        }
    }

    // --- der ganze Weg, vom Webhook bis zum Lauf ----------------------------

    public function test_a_signed_webhook_starts_the_flow_that_waits_for_it(): void
    {
        Queue::fake();

        $created = $this->automationStartingOn('vocalflow.session_created');
        $completed = $this->automationStartingOn('vocalflow.session_completed');

        $this->send('session-created')->assertStatus(200);

        $this->assertSame(1, $this->runsFor($created));
        $this->assertSame(0, $this->runsFor($completed), 'Der Auslöser fuer abgeschlossene Sessions ist auf eine Anlage gelaufen.');
    }

    public function test_the_flow_gets_the_flattened_session_and_not_only_the_raw_payload(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('vocalflow.session_created');

        $this->send('session-created')->assertStatus(200);

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('Nina Sömmer', data_get($run->context, 'student.name'));
        $this->assertSame('einzelstunde', data_get($run->context, 'session.session_type_slug'));

        // Und die Rohnutzlast liegt daneben, fuer den Fall, den die Auswahl
        // nicht trifft.
        $this->assertSame('new_booking', data_get($run->context, 'vocalflow.metadata.business_impact'));
    }

    public function test_the_session_type_filter_holds_over_the_whole_path(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('vocalflow.session_created', ['session_type_slug' => 'schnupperstunde']);

        $this->send('session-created')->assertStatus(200);

        $this->assertSame(0, $this->runsFor($automation));
    }

    public function test_the_published_session_reaches_its_flow(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('vocalflow.session_published');

        $this->call('POST', self::PUBLISHED_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::PUBLICATION_SECRET,
        ], (string) file_get_contents(__DIR__.'/../Fixtures/vocalflow/session-published.json'))->assertStatus(200);

        $this->assertSame(1, $this->runsFor($automation));

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('nina@example.com', data_get($run->context, 'student.email'));
        $this->assertSame('019bffde-1a2b-7000-8000-000000000001', data_get($run->context, 'session.id'));
    }

    // --- Helfer -------------------------------------------------------------

    /**
     * @return array<string, class-string>
     */
    private function triggersByEvent(): array
    {
        return [
            'session.created' => VfT\SessionCreatedTrigger::class,
            'session.completed' => VfT\SessionCompletedTrigger::class,
            'task.created' => VfT\TaskCreatedTrigger::class,
            'task.updated' => VfT\TaskUpdatedTrigger::class,
            'task.assigned' => VfT\TaskAssignedTrigger::class,
            'task.deleted' => VfT\TaskDeletedTrigger::class,
        ];
    }

    private function fixtureFor(string $event): string
    {
        return str_replace('.', '-', $event);
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

    private function send(string $fixture): TestResponse
    {
        // Die Bytes mit PHPs Vorgabe-Flags, die Signatur ueber die kanonische
        // Fassung — genau wie VocalFlow es tut. Ein Test, der beide aus
        // derselben Zeichenkette bildet, pruefte nicht, worauf es ankommt.
        $wire = json_encode($this->fixture($fixture), JSON_THROW_ON_ERROR);
        $canonical = (string) VocalFlowSignature::canonical(json_decode($wire, false));

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => VocalFlowSignature::sign(self::SECRET, $canonical),
        ], $wire);
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
}
