<?php

/*
 * "Wo springen sie ab?": the panel of an automation on the funnels map shows its mails
 * as a step table, in the order of the flow, with what became of the people who started
 * in the period. Everything here is read only: opening the panel counts nothing.
 */

use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Marketing\Services\MessageEngagement;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Fixtures/MessageEngagementStub.php';

/** A series: trigger, mail 1, a day, the newsletter-day gate, mail 2. */
function stepSeries(): Automation
{
    $automation = Automation::query()->create(['uuid' => 'uuid-kurs', 'name' => 'Kurs', 'handle' => 'kurs', 'enabled' => true]);

    foreach ([
        ['trig', 'event_trigger', []],
        ['m1', 'marketing.send_email', ['subject' => 'Tag 1', 'template' => 'tag-1']],
        ['w1', 'delay', ['amount' => 1, 'unit' => 'days']],
        ['g2', 'newsletter_day_gate', []],
        ['m2', 'marketing.send_email', ['subject' => 'Tag 2', 'template' => 'tag-2']],
    ] as [$key, $type, $config]) {
        AutomationNode::query()->create(['automation_id' => $automation->id, 'node_key' => $key, 'type' => $type, 'config' => $config]);
    }

    foreach ([['trig', 'm1'], ['m1', 'w1'], ['w1', 'g2'], ['g2', 'm2']] as [$from, $to]) {
        AutomationEdge::query()->create(['automation_id' => $automation->id, 'from_node_key' => $from, 'to_node_key' => $to]);
    }

    return $automation;
}

/**
 * One person's run. `$steps` maps a node key to its outcome: a message uuid (sent), 'stopped', 'failed',
 * or an array for a hand-made output.
 *
 * @param  array<string, string|array<string, mixed>>  $steps
 */
function stepRun(Automation $automation, ?string $who, string $status, array $steps, string $startedAt = '2026-08-10 09:00:00', bool $isTest = false): AutomationRun
{
    $run = AutomationRun::query()->create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'trigger_type' => 'event_trigger',
        'subject_key' => $who,
        'status' => $status,
        'started_at' => $startedAt,
        'is_test' => $isTest,
    ]);

    foreach ($steps as $key => $outcome) {
        [$nodeStatus, $output] = match (true) {
            is_array($outcome) => ['failed', $outcome],
            $outcome === 'stopped' => ['stopped', []],
            $outcome === 'failed' => ['failed', []],
            default => ['success', ['sent' => true, 'message_uuid' => $outcome]],
        };

        AutomationNodeRun::query()->create([
            'automation_run_id' => $run->id,
            'automation_uuid' => $automation->uuid,
            'node_key' => $key,
            'node_type' => 'marketing.send_email',
            'status' => $nodeStatus,
            'output' => $output,
            'is_test' => $isTest,
        ]);
    }

    return $run;
}

function stepWaits(AutomationRun $run, string $nodeKey, string $dueAt): void
{
    AutomationScheduledJob::query()->create([
        'automation_id' => $run->automation_id,
        'automation_run_id' => $run->id,
        'node_key' => $nodeKey,
        'due_at' => $dueAt,
        'status' => AutomationScheduledJob::STATUS_PENDING,
        'payload' => [],
    ]);
}

/** The scenario of the table: eight runs, three of them not to be counted. */
function stepScenario(): Automation
{
    $automation = stepSeries();

    MessageEngagement::$calls = [];
    MessageEngagement::$byUuid = [
        'u-a1' => ['email' => 'a@x.de', 'opened' => true, 'clicked' => true],
        'u-b1' => ['email' => 'b@x.de', 'proxy' => true],
        'u-c1' => ['email' => 'c@x.de', 'opened' => true],
        'u-c2' => ['email' => 'c@x.de', 'opened' => true, 'clicked' => true],
        'u-d1' => ['email' => 'd@x.de'],
        'u-h1' => ['email' => 'h@x.de', 'unsub' => true],
        'u-i1' => ['email' => 'i@x.de'],
        'u-f1' => ['email' => 'f@x.de', 'opened' => true],
        'u-g1' => ['email' => 'g@x.de', 'opened' => true],
    ];

    stepWaits(stepRun($automation, 'a@x.de', 'waiting', ['m1' => 'u-a1']), 'w1', '2026-08-21 10:00:00');
    stepWaits(stepRun($automation, 'b@x.de', 'waiting', ['m1' => 'u-b1']), 'g2', '2026-08-21 09:00:00');
    stepRun($automation, 'c@x.de', 'success', ['m1' => 'u-c1', 'm2' => 'u-c2']);
    stepRun($automation, 'd@x.de', 'stopped', ['m1' => 'u-d1', 'm2' => 'stopped']);
    stepRun($automation, 'h@x.de', 'failed', ['m1' => 'u-h1', 'm2' => 'failed']);
    stepRun($automation, 'i@x.de', 'cancelled', ['m1' => 'u-i1']);

    // Not to be counted: a run nobody is behind (parallel trigger, "No Recipient to act on"), a test run, one before the period.
    stepRun($automation, null, 'failed', ['m1' => ['missing_data_reference' => 'to']]);
    stepRun($automation, 'f@x.de', 'success', ['m1' => 'u-f1'], isTest: true);
    stepRun($automation, 'g@x.de', 'success', ['m1' => 'u-g1'], startedAt: '2026-06-01 09:00:00');

    return $automation;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00'));
    $this->map = new AutomationMapObjects(app(AutomationRepository::class));
    $this->period = MapPeriod::lastDays(30);
});

afterEach(function () {
    Carbon::setTestNow();
    MessageEngagement::$byUuid = [];
});

it('shows the mails and waits in flow order with people counted per step', function () {
    stepScenario();

    $steps = $this->map->details('kurs', $this->period)[0];

    expect($steps['kind'])->toBe('list')
        ->and($steps['title'])->toBe('Where do they drop off? (last 30 days)')
        ->and(array_column($steps['items'], 'title'))->toBe(['Tag 1', 'Wait: after 1 day', 'Tag 2'])
        ->and(array_column($steps['items'], 'meta'))->toBe([
            'Sent 6 · Opened 2 (+1 proxy only) · Clicked 1 · Unsubscribed 1',
            'Waiting now: 2 · next due 21. Aug, 09:00',
            'Sent 1 · Opened 1 · Clicked 1 · Stopped here 2',
        ]);
});

it('says how the people who started fared, and what was left out', function () {
    stepScenario();

    $block = $this->map->details('kurs', $this->period)[1];
    $rows = array_column($block['rows'], 'value', 'label');

    expect($block['kind'])->toBe('facts')
        ->and($rows)->toMatchArray([
            'Started' => '6 people',
            'Still waiting' => '2 people',
            'Finished' => '1 person',
            'Unsubscribed' => '1 person',
            'Stopped' => '1 person',
            'Cancelled' => '1 person',
            'Failed' => '1 person',
            'Skipped, no recipient (not counted above)' => '1 run',
        ])
        ->and(array_keys($rows))->toContain('How it is counted');
});

it('counts a person once when they have two runs', function () {
    $automation = stepScenario();
    stepWaits(stepRun($automation, 'B@x.de', 'waiting', ['m1' => 'u-b1']), 'g2', '2026-08-22 09:00:00');

    $details = $this->map->details('kurs', $this->period);

    expect($details[0]['items'][1]['meta'])->toBe('Waiting now: 2 · next due 21. Aug, 09:00')
        ->and(array_column($details[1]['rows'], 'value', 'label')['Started'])->toBe('6 people');
});

it('reads the period from the start of the run, not from the mail', function () {
    stepScenario();

    $short = $this->map->details('kurs', MapPeriod::lastDays(5));
    $long = $this->map->details('kurs', MapPeriod::lastDays(90));

    // Runs started 10 Aug: none within 5 days, and the plain list of mails stays.
    expect($short)->toHaveCount(1)
        ->and($short[0]['title'])->toBe('Mails')
        // 90 days reaches the run of 1 June as well.
        ->and(array_column($long[1]['rows'], 'value', 'label')['Started'])->toBe('7 people');
});

it('says the wait that nobody is in as an empty wait', function () {
    $automation = stepSeries();
    stepRun($automation, 'c@x.de', 'success', ['m1' => 'u-c1', 'm2' => 'u-c2']);

    expect($this->map->details('kurs', $this->period)[0]['items'][1]['meta'])->toBe('Waiting now: nobody');
});

it('writes the numbers in German in the panel', function () {
    app()->setLocale('de');
    stepScenario();

    $details = $this->map->details('kurs', $this->period);

    expect($details[0]['title'])->toBe('Wo springen sie ab? (letzte 30 Tage)')
        ->and($details[0]['items'][0]['meta'])->toBe('Gesendet 6 · Geöffnet 2 (+1 nur Proxy) · Geklickt 1 · Abgemeldet 1')
        ->and($details[0]['items'][1])->toBe(['title' => 'Warten: nach 1 Tag', 'meta' => 'Wartet jetzt: 2 · nächste fällig 21. Aug, 09:00', 'image' => null])
        ->and($details[0]['items'][2]['meta'])->toBe('Gesendet 1 · Geöffnet 1 · Geklickt 1 · Hier gestoppt 2')
        ->and(array_column($details[1]['rows'], 'value', 'label')['Gestartet'])->toBe('6 Personen');
});

it('shows the display time zone of the Control Panel for the next due time', function () {
    config()->set('statamic.system.display_timezone', 'Europe/Berlin');
    stepScenario();

    expect($this->map->details('kurs', $this->period)[0]['items'][1]['meta'])->toBe('Waiting now: 2 · next due 21. Aug, 11:00');
});

it('counts only the sends of a mail that has no marketing numbers', function () {
    $automation = Automation::query()->create(['uuid' => 'uuid-plain', 'name' => 'Plain', 'handle' => 'plain', 'enabled' => true]);
    AutomationNode::query()->create(['automation_id' => $automation->id, 'node_key' => 'p1', 'type' => 'send_email', 'config' => ['subject' => 'Hallo']]);

    foreach (['a@x.de', 'a@x.de', 'b@x.de'] as $who) {
        $run = AutomationRun::query()->create(['automation_id' => $automation->id, 'automation_uuid' => 'uuid-plain', 'trigger_type' => 'x', 'subject_key' => $who, 'status' => 'success', 'started_at' => '2026-08-10 09:00:00', 'is_test' => false]);
        AutomationNodeRun::query()->create(['automation_run_id' => $run->id, 'automation_uuid' => 'uuid-plain', 'node_key' => 'p1', 'node_type' => 'send_email', 'status' => 'success', 'output' => ['sent' => true], 'is_test' => false]);
    }

    expect($this->map->details('plain', $this->period)[0]['items'][0]['meta'])->toBe('Sent 2');
});

it('still shows who was sent what when marketing cannot count the opens', function () {
    stepScenario();
    $without = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        protected function engagementOf(array $messageUuids): ?array
        {
            return null;
        }
    };

    $items = $without->details('kurs', $this->period)[0]['items'];

    expect($items[0]['meta'])->toBe('Sent 6')
        ->and($items[2]['meta'])->toBe('Sent 1 · Stopped here 2');
});

it('opens the panel without writing anything and asks marketing once per mail', function () {
    stepScenario();
    $tables = [AutomationRun::class, AutomationNodeRun::class, AutomationScheduledJob::class, AutomationNode::class, Automation::class];
    $count = fn () => array_map(fn ($model) => [$model::query()->count(), $model::query()->max('updated_at')], $tables);
    $before = $count();
    MessageEngagement::$calls = [];

    $this->map->details('kurs', $this->period);
    $this->map->details('kurs', $this->period);

    expect($count())->toBe($before)
        ->and(MessageEngagement::$calls)->toHaveCount(4);
});
