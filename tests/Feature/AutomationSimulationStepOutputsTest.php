<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;

/*
 * The real series ask what an earlier step found out: "did the welcome series run to the end"
 * (`nodes.wait_welcome.ran_to_end`), "does she hold the course" (`nodes.mk_v1.has_access`). The dry
 * run runs no step, so it used to end there ("depends on what an earlier step delivers") and a known
 * person saw nothing of the series. Two of those answers come from what the dry run already knows
 * about her: the automations she has been through and the access she holds. Those are read, so the
 * way carries on; every other answer (a click, a recent grant) stays unknown, and says so.
 */

/**
 * @param  list<array{string, string, array}>  $nodes  key, type, config
 * @param  list<array{string, string, string}>  $edges  from, output, to
 */
function sopGraph(array $nodes, array $edges): void
{
    $a = Automation::query()->create(['uuid' => 'uuid-sop', 'name' => 'Sop', 'handle' => 'sop', 'enabled' => true]);

    foreach ($nodes as [$key, $type, $config]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'config' => $config]);
    }

    foreach ($edges as [$from, $output, $to]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }
}

/** @return list<string> kind:label of every effect, depth first */
function sopFlat(array $effects): array
{
    $out = [];

    foreach ($effects as $e) {
        $out[] = $e->kind.':'.$e->label;
        array_push($out, ...sopFlat($e->children));
    }

    return $out;
}

function sopReact(SimPerson $person): array
{
    return app(AutomationMapObjects::class)->react('sop', new SimEvent(SimEvent::STARTED), $person, CarbonImmutable::parse('2026-10-21 10:00:00'));
}

function sopWelcomeGate(): void
{
    sopGraph([
        ['trigger', 'manual', []],
        ['wait_welcome', 'wait_for_automation', ['email' => '{{ subscriber.email }}', 'automation' => 'welcome-daily', 'poll_hours' => 6]],
        ['welcome_done', 'branch', ['mode' => 'all', 'conditions' => [['field' => 'nodes.wait_welcome.ran_to_end', 'operator' => 'equals', 'value' => 'true']]]],
        ['end_early', 'stop', []],
        ['send_v1', 'marketing.send_email', ['template' => 'v1', 'subject' => 'Vokale 1', 'list' => 'newsletter']],
    ], [
        ['trigger', 'default', 'wait_welcome'],
        ['wait_welcome', 'default', 'welcome_done'],
        ['welcome_done', 'true', 'send_v1'],
        ['welcome_done', 'false', 'end_early'],
    ]);
}

it('takes the way of a person who has been through the awaited automation', function () {
    sopWelcomeGate();

    $flat = sopFlat(sopReact(SimPerson::known([], [], ['welcome-daily'])));

    expect($flat)->toContain('mail:Vokale 1')
        ->and(implode('|', $flat))->not->toContain('unknown:');
});

it('ends the way of a person who has not been through it, and says why', function () {
    sopWelcomeGate();

    $flat = sopFlat(sopReact(SimPerson::known()));

    expect(implode('|', $flat))->toContain('decision:')
        ->and($flat)->not->toContain('mail:Vokale 1')
        ->and(implode('|', $flat))->toContain('stop:')
        ->and(implode('|', $flat))->not->toContain('unknown:');
});

it('reads the access a person holds where a step reports it', function () {
    sopGraph([
        ['trigger', 'manual', []],
        ['own', 'entitlements.check_access', ['email' => '{{ subscriber.email }}', 'product_slug' => 'cvt-im-chor-minikurs', 'mode' => 'report']],
        ['has', 'branch', ['mode' => 'all', 'conditions' => [['field' => 'nodes.own.has_access', 'operator' => 'equals', 'value' => 'true']]]],
        ['yes', 'marketing.send_email', ['template' => 'mit', 'subject' => 'Mit Kurs', 'list' => 'newsletter']],
        ['no', 'marketing.send_email', ['template' => 'ohne', 'subject' => 'Ohne Kurs', 'list' => 'newsletter']],
    ], [
        ['trigger', 'default', 'own'],
        ['own', 'default', 'has'],
        ['has', 'true', 'yes'],
        ['has', 'false', 'no'],
    ]);

    $with = sopFlat(sopReact(SimPerson::known([], ['cvt-im-chor-minikurs'])));
    $without = sopFlat(sopReact(SimPerson::known()));

    expect($with)->toContain('mail:Mit Kurs')->not->toContain('mail:Ohne Kurs')
        ->and($without)->toContain('mail:Ohne Kurs')->not->toContain('mail:Mit Kurs');
});

it('stops a person where a step requires an access she does not hold', function () {
    sopGraph([
        ['trigger', 'manual', []],
        ['need', 'entitlements.check_access', ['email' => '{{ subscriber.email }}', 'product_slug' => 'kurs', 'mode' => 'require']],
        ['m', 'marketing.send_email', ['template' => 't', 'subject' => 'Nur mit Kurs', 'list' => 'newsletter']],
    ], [['trigger', 'default', 'need'], ['need', 'default', 'm']]);

    expect(sopFlat(sopReact(SimPerson::known([], ['kurs']))))->toContain('mail:Nur mit Kurs')
        ->and(sopFlat(sopReact(SimPerson::known())))->not->toContain('mail:Nur mit Kurs');
});

it('does not decide for a step that looks up somebody else, or asks for a field only a run knows', function () {
    sopGraph([
        ['trigger', 'manual', []],
        ['own', 'entitlements.check_access', ['email' => 'fest@example.com', 'product_slug' => 'kurs', 'mode' => 'report']],
        ['own2', 'entitlements.check_access', ['email' => '{{ subscriber.email }}', 'product_slug' => 'kurs', 'mode' => 'report']],
        ['has', 'branch', ['mode' => 'all', 'conditions' => [['field' => 'nodes.own.has_access', 'operator' => 'equals', 'value' => 'true']]]],
        ['st', 'branch', ['mode' => 'all', 'conditions' => [['field' => 'nodes.own2.state', 'operator' => 'equals', 'value' => 'active']]]],
        ['m', 'marketing.send_email', ['template' => 't', 'subject' => 'Weiter', 'list' => 'newsletter']],
    ], [['trigger', 'default', 'own'], ['own', 'default', 'has'], ['has', 'true', 'own2'], ['has', 'false', 'own2'], ['own2', 'default', 'st'], ['st', 'true', 'm']]);

    $flat = implode('|', sopFlat(sopReact(SimPerson::known([], ['kurs']))));

    // Both questions stay open: the first step looks up another address, the second asks for the state of a grant.
    expect(substr_count($flat, 'unknown:'))->toBeGreaterThanOrEqual(1)
        ->and($flat)->not->toContain('mail:Weiter');
});

it('still says "unknown" for an answer only a run could give', function () {
    sopGraph([
        ['trigger', 'manual', []],
        ['clicked', 'marketing.message_clicked', ['message_uuid' => '{{ nodes.x.message_uuid }}']],
        ['has', 'branch', ['mode' => 'all', 'conditions' => [['field' => 'nodes.clicked.clicked', 'operator' => 'equals', 'value' => 'true']]]],
        ['m', 'marketing.send_email', ['template' => 't', 'subject' => 'Klick', 'list' => 'newsletter']],
    ], [['trigger', 'default', 'clicked'], ['clicked', 'default', 'has'], ['has', 'true', 'm']]);

    $flat = sopFlat(sopReact(SimPerson::known()));

    expect(implode('|', $flat))->toContain('unknown:')
        ->and($flat)->not->toContain('mail:Klick');
});
