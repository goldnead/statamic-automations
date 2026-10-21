<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * Parity: the planner of the dry run against the real engine in test mode.
 *
 * The planner promises "this is what the automation would do for this person". The only way to keep
 * that promise is to hold it against the engine, so for the same automation and the same person both
 * are asked, and the steps that matter to the person (mails and waits, in order, with the length of
 * each wait) must be the same. A branch the planner takes differently from the engine fails here.
 */

function parityBuild(): Automation
{
    $a = Automation::query()->create(['uuid' => 'uuid-parity', 'name' => 'Parity', 'handle' => 'parity', 'enabled' => true]);

    $steps = [
        't' => ['manual', []],
        'm1' => ['send_email', ['to' => 'x@example.test', 'subject' => 'Willkommen', 'body' => 'Hi']],
        'w1' => ['delay', ['amount' => 2, 'unit' => 'days']],
        'b' => ['branch', ['mode' => 'all', 'conditions' => [['field' => 'contact.tags', 'operator' => 'includes_tag', 'value' => 'chorleitung']]]],
        'm2' => ['send_email', ['to' => 'x@example.test', 'subject' => 'Chor', 'body' => 'Hi']],
        's' => ['switch', ['value' => '{{ contact.level }}', 'cases' => ['anfaenger' => 'anfaenger', 'profi' => 'profi']]],
        'm3' => ['send_email', ['to' => 'x@example.test', 'subject' => 'Anfaenger', 'body' => 'Hi']],
        'm4' => ['send_email', ['to' => 'x@example.test', 'subject' => 'Profi', 'body' => 'Hi']],
        'm5' => ['send_email', ['to' => 'x@example.test', 'subject' => 'Sonst', 'body' => 'Hi']],
        'w2' => ['delay', ['amount' => 3, 'unit' => 'hours']],
        'f' => ['filter', ['mode' => 'all', 'conditions' => [['field' => 'contact.optout', 'operator' => 'is_empty']]]],
        'm6' => ['send_email', ['to' => 'x@example.test', 'subject' => 'Zum Schluss', 'body' => 'Hi']],
    ];

    foreach ($steps as $key => [$type, $config]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'config' => $config]);
    }

    $wires = [
        ['t', 'm1', 'default'], ['m1', 'w1', 'default'], ['w1', 'b', 'default'],
        ['b', 'm2', 'true'], ['b', 's', 'false'],
        ['s', 'm3', 'anfaenger'], ['s', 'm4', 'profi'], ['s', 'm5', 'default'],
        ['m2', 'w2', 'default'], ['m3', 'w2', 'default'], ['m4', 'w2', 'default'], ['m5', 'w2', 'default'],
        ['w2', 'f', 'default'], ['f', 'm6', 'default'],
    ];

    foreach ($wires as [$from, $to, $output]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }

    return $a->fresh(['nodes', 'edges']);
}

/**
 * What the engine does for this person: mails and waits in the order they happen, read from the
 * run's own log. In test mode a delay does not pause the run, it logs the seconds it would have waited.
 *
 * @return list<string> "mail:<node>" and "wait:<node>:<seconds>"
 */
function parityReal(Automation $a, array $traits): array
{
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));

    $context = AutomationContext::make(Arr::undot($traits), testMode: true);
    $runner = app(WorkflowRunner::class);
    $run = $runner->createRun($a, $context, $a->nodes->firstWhere('node_key', 't'));
    $runner->execute($run, $context);

    $seen = [];

    $lines = [];

    foreach (AutomationNodeRun::query()->where('automation_run_id', $run->id)->orderBy('id')->get() as $nodeRun) {
        if (isset($seen[$nodeRun->node_key])) {
            continue;
        }

        $seen[$nodeRun->node_key] = true;

        if ($nodeRun->node_type === 'send_email') {
            $lines[] = 'mail:'.$nodeRun->node_key;
        } elseif ($nodeRun->node_type === 'delay') {
            // In test mode a delay does not pause the run: it records how long it would have.
            $lines[] = 'wait:'.$nodeRun->node_key.':'.(int) ($nodeRun->output['would_wait_seconds'] ?? -1);
        }
    }

    Carbon::setTestNow();

    return $lines;
}

/** @return list<string> the same lines, from the planner */
function parityPlanned(array $traits): array
{
    $effects = app(AutomationMapObjects::class)->react('parity', new SimEvent(SimEvent::STARTED), SimPerson::known($traits), CarbonImmutable::now());
    $lines = [];

    $walk = function (array $list) use (&$walk, &$lines) {
        foreach ($list as $e) {
            if ($e->kind === Effect::MAIL) {
                $lines[] = 'mail:'.$e->meta['node'];
            } elseif ($e->kind === Effect::WAIT) {
                $lines[] = 'wait:'.$e->meta['node'].':'.$e->afterSeconds;
            }

            $walk($e->children);
        }
    };
    $walk($effects);

    return $lines;
}

dataset('parity people', [
    'a choir leader' => [['contact.tags' => ['chorleitung'], 'contact.level' => 'profi']],
    'a beginner' => [['contact.tags' => [], 'contact.level' => 'anfaenger']],
    'a professional who opted out' => [['contact.tags' => [], 'contact.level' => 'profi', 'contact.optout' => 'yes']],
    'someone the switch does not know' => [['contact.tags' => ['solo'], 'contact.level' => 'unbekannt']],
    'someone with nothing known' => [[]],
]);

it('plans what the engine does in test mode, for the same person', function (array $traits) {
    $a = parityBuild();

    expect(parityPlanned($traits))->toBe(parityReal($a, $traits));
})->with('parity people');

it('says which way a known person took and why', function () {
    parityBuild();

    $effects = app(AutomationMapObjects::class)->react('parity', new SimEvent(SimEvent::STARTED), SimPerson::known(['contact.tags' => ['chorleitung']]), CarbonImmutable::now());
    $decisions = [];
    $walk = function (array $list) use (&$walk, &$decisions) {
        foreach ($list as $e) {
            if ($e->kind === Effect::DECISION) {
                $decisions[] = $e->label.': '.$e->detail;
            }

            $walk($e->children);
        }
    };
    $walk($effects);

    expect($decisions)->toContain('Branch: took the way “if the tag ‘chorleitung’ is set”')
        ->and($decisions)->toContain('Filter: took the way “if contact optout is empty”');
});
