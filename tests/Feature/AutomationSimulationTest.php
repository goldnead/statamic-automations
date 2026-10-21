<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The dry run of an automation: what the map's Probelauf would show. Nothing is executed, so the
 * assertions are about the effects, their order, and (below) that nothing at all was written.
 */

function simAutomation(string $handle, bool $enabled = true): Automation
{
    return Automation::query()->create([
        'uuid' => 'uuid-'.$handle,
        'name' => ucfirst($handle),
        'handle' => $handle,
        'enabled' => $enabled,
    ]);
}

function simStep(Automation $a, string $key, string $type, array $config = []): AutomationNode
{
    return AutomationNode::query()->create([
        'automation_id' => $a->id,
        'node_key' => $key,
        'type' => $type,
        'config' => $config,
    ]);
}

function simWire(Automation $a, string $from, string $to, string $output = 'default'): void
{
    AutomationEdge::query()->create([
        'automation_id' => $a->id,
        'from_node_key' => $from,
        'from_output' => $output,
        'to_node_key' => $to,
    ]);
}

/** A straight line of steps. */
function simLine(Automation $a, array $keys): void
{
    foreach (array_slice($keys, 0, -1) as $i => $from) {
        simWire($a, $from, $keys[$i + 1]);
    }
}

/** "kind:label" of an effect tree, depth first. */
function simEffects(array $effects): array
{
    $out = [];

    foreach ($effects as $e) {
        $out[] = $e->kind.':'.$e->label;
        array_push($out, ...simEffects($e->children));
    }

    return $out;
}

function simReact(string $handle, ?SimPerson $person = null): array
{
    return app(AutomationMapObjects::class)->react($handle, new SimEvent(SimEvent::STARTED), $person ?? SimPerson::unknown(), CarbonImmutable::now());
}

it('turns a welcome series into mail, wait, mail in the order the edges say', function () {
    $a = simAutomation('serie');
    simStep($a, 'm2', 'send_email', ['subject' => 'Tag 3']);
    simStep($a, 'w1', 'delay', ['amount' => 2, 'unit' => 'days']);
    simStep($a, 't', 'manual');
    simStep($a, 'm1', 'send_email', ['subject' => 'Willkommen', 'template' => 'welcome']);
    simLine($a, ['t', 'm1', 'w1', 'm2']);

    $effects = simReact('serie');

    expect(simEffects($effects))->toBe(['mail:Willkommen', 'wait:after 2 days', 'mail:Tag 3'])
        ->and($effects[0]->detail)->toBe('Template welcome')
        ->and($effects[0]->children[0]->afterSeconds)->toBe(2 * 86400);
});

it('forks at a branch with the condition in words, and at a filter with the stop', function () {
    $a = simAutomation('weiche');
    simStep($a, 't', 'manual');
    simStep($a, 'b', 'branch', ['mode' => 'all', 'conditions' => [['field' => 'lead.status', 'operator' => 'equals', 'value' => 'Qualified']]]);
    simStep($a, 'yes', 'send_email', ['subject' => 'Qualified mail']);
    simStep($a, 'no', 'send_email', ['subject' => 'Nurture mail']);
    simWire($a, 't', 'b');
    simWire($a, 'b', 'yes', 'true');
    simWire($a, 'b', 'no', 'false');

    $fork = simReact('weiche')[0];

    expect($fork->kind)->toBe(Effect::FORK)
        ->and(simEffects($fork->children))->toBe([
            'alt:if lead status = Qualified', 'mail:Qualified mail',
            'alt:otherwise', 'mail:Nurture mail',
        ]);

    $f = simAutomation('filter');
    simStep($f, 't', 'manual');
    simStep($f, 'f', 'filter', ['conditions' => [['field' => 'x', 'operator' => 'is_empty']]]);
    simStep($f, 'm', 'send_email', ['subject' => 'Only if']);
    simLine($f, ['t', 'f', 'm']);

    expect(simEffects(simReact('filter')))->toBe([
        'fork:Filter',
        'alt:if x is empty', 'mail:Only if',
        'alt:otherwise', 'stop:The flow ends: the filter does not let it through',
    ]);
});

it('forks at a switch, one alternative for each case', function () {
    $a = simAutomation('schalter');
    simStep($a, 't', 'manual');
    simStep($a, 's', 'switch', ['cases' => ['chor' => 'chor', 'solo' => 'solo']]);
    simStep($a, 'c', 'send_email', ['subject' => 'Chor']);
    simStep($a, 'o', 'send_email', ['subject' => 'Solo']);
    simWire($a, 't', 's');
    simWire($a, 's', 'c', 'chor');
    simWire($a, 's', 'o', 'solo');

    $alts = simReact('schalter')[0]->children;

    expect(array_map(fn ($c) => $c->label, $alts))->toContain('case chor', 'case solo')
        ->and(simEffects($alts))->toContain('mail:Chor', 'mail:Solo');
});

it('says a switched off automation does not run, and a missing one is unknown', function () {
    $a = simAutomation('aus', enabled: false);
    simStep($a, 't', 'manual');

    expect(simEffects(simReact('aus')))->toBe(['stop:Automation is off: it does not run'])
        ->and(simEffects(simReact('gibtsnicht')))->toBe(['unknown:Automation not found']);
});

it('names a step it does not model instead of guessing, and leaves variables out', function () {
    $a = simAutomation('fremd');
    simStep($a, 't', 'manual');
    simStep($a, 'v', 'set_variable');
    simStep($a, 'u', 'create_user', []);
    simStep($a, 'm', 'send_email', ['subject' => 'After']);
    simLine($a, ['t', 'v', 'u', 'm']);

    $flat = simEffects(simReact('fremd'));

    expect($flat[0])->toStartWith('unknown:')
        ->and($flat[1])->toBe('mail:After')
        ->and($flat)->toHaveCount(2);
});

it('hands a call to another automation on as an event with a target', function () {
    $a = simAutomation('anrufer');
    simAutomation('angerufen');
    simStep($a, 't', 'manual');
    simStep($a, 'c', 'call_automation', ['automation' => 'angerufen']);
    simLine($a, ['t', 'c']);

    $emit = simReact('anrufer')[0];

    expect($emit->kind)->toBe(Effect::EMIT)
        ->and($emit->emits->target)->toBe(['type' => 'automation', 'id' => 'angerufen']);
});

it('starts a path at an automation that has a trigger and only there', function () {
    $a = simAutomation('mit');
    simStep($a, 't', 'manual');
    $b = simAutomation('ohne');
    simStep($b, 'm', 'send_email');

    $map = app(AutomationMapObjects::class);

    expect($map->paths('mit'))->toHaveCount(1)
        ->and($map->paths('mit')[0]->type)->toBe(SimEvent::STARTED)
        ->and($map->paths('ohne'))->toBe([])
        ->and($map->paths('nope'))->toBe([]);
});

it('lets only the right that shows automations see the dry run', function () {
    Permission::register('view automations');
    Role::make('viewer')->title('Viewer')->addPermission('view automations')->save();
    $viewer = User::make()->email('v@example.test')->assignRole('viewer')->save();
    $nobody = User::make()->email('n@example.test')->save();

    $map = app(AutomationMapObjects::class);

    expect($map->simulationAuthorized('x', User::find($viewer->id()) ?? $viewer))->toBeTrue()
        ->and($map->simulationAuthorized('x', $nobody))->toBeFalse()
        ->and($map->simulationAuthorized('x', null))->toBeFalse();

    $registry = app(MapObjectRegistry::class);

    expect($registry->simulationAllowed('automation', 'x', null))->toBeFalse();
});

/*
 * The whole thing through the planner: two automations, the first calls the second.
 */
it('plans the tree of two automations in order and counts its leaves', function () {
    $a = simAutomation('eins');
    simStep($a, 't', 'manual');
    simStep($a, 'm', 'send_email', ['subject' => 'Eins']);
    simStep($a, 'w', 'delay', ['amount' => 1, 'unit' => 'days']);
    simStep($a, 'c', 'call_automation', ['automation' => 'zwei']);
    simLine($a, ['t', 'm', 'w', 'c']);
    $b = simAutomation('zwei');
    simStep($b, 't', 'manual');
    simStep($b, 'm', 'send_email', ['subject' => 'Zwei']);
    simLine($b, ['t', 'm']);

    $registry = app(MapObjectRegistry::class);
    $nodes = [['type' => 'automation', 'id' => 'eins'], ['type' => 'automation', 'id' => 'zwei']];
    $edges = [['from_type' => 'automation', 'from_id' => 'eins', 'to_type' => 'automation', 'to_id' => 'zwei', 'kind' => 'starts']];

    // Without a user who may see automations the tree says so instead of showing mails.
    $denied = (new Simulator($registry))->tree($nodes, $edges, SimPerson::unknown(), null);

    // Both: "zwei" is not reached through the denied "eins", so it begins on its own trigger, and is denied too.
    expect(collect($denied['roots'])->pluck('children.0.kind')->all())->toBe(['denied', 'denied']);

    $admin = User::make()->email('a@example.test')->makeSuper();
    $admin->save();

    $tree = (new Simulator($registry))->tree($nodes, $edges, SimPerson::unknown(), $admin);
    $flat = [];
    $walk = function (array $list) use (&$walk, &$flat) {
        foreach ($list as $n) {
            $flat[] = $n['kind'].':'.$n['label'];
            $walk($n['children']);
        }
    };
    $walk($tree['roots']);

    expect($tree['roots'])->toHaveCount(1) // "zwei" is reached by "eins", so it starts nothing of its own
        ->and($flat)->toBe([
            'enter:Eins',
            'mail:Eins',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'wait:after 1 day',
            'enter:Zwei',
            'mail:Zwei',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
        ])
        ->and($tree['stats'])->toBe(['nodes' => 9, 'leaves' => 1, 'cut' => 0, 'unreached' => []]);
});

it('writes nothing, sends nothing, dispatches nothing and adds no run', function () {
    $a = simAutomation('eins');
    simStep($a, 't', 'manual');
    simStep($a, 'm', 'send_email', ['subject' => 'Eins']);
    simStep($a, 'b', 'branch', ['conditions' => []]);
    simStep($a, 'g', 'entitlements.grant', ['product_slug' => 'kurs']);
    simLine($a, ['t', 'm', 'b']);
    simWire($a, 'b', 'g', 'true');

    Mail::fake();
    Queue::fake();
    $before = AutomationRun::query()->count();

    // Every event the dry run raises, by name. Reading models and querying raise the framework's own; nothing else may.
    $events = [];
    Event::listen('*', function (string $name) use (&$events) {
        $events[] = $name;
    });

    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    simReact('eins');
    app(AutomationMapObjects::class)->paths('eins');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();

    $own = array_values(array_filter($events, fn (string $name) => ! preg_match('/^eloquent\.(retrieved|booting|booted)/', $name)
        && ! in_array($name, [StatementPrepared::class, QueryExecuted::class], true)));

    expect($writes)->toBe([])
        ->and($own)->toBe([])
        ->and(AutomationRun::query()->count())->toBe($before);
});

it('plans a chain of branches whose two sides lead to the same step without doubling at every one', function () {
    $a = simAutomation('raute');
    simStep($a, 't', 'manual');
    $previous = 't';

    foreach (range(1, 30) as $i) {
        simStep($a, "b$i", 'branch', ['conditions' => [['field' => 'x', 'operator' => 'equals', 'value' => (string) $i]]]);
        simStep($a, "m$i", 'send_email', ['subject' => "Mail $i"]);
        simWire($a, $previous, "b$i");
        simWire($a, "b$i", "m$i", 'true');
        simWire($a, "b$i", "m$i", 'false');
        $previous = "m$i";
    }

    $started = microtime(true);
    $effects = simReact('raute');

    // 2^30 ways in the written graph; planned in a moment, as a graph that shares what is the same.
    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and($effects)->toHaveCount(1);
});

it('cuts a graph that is larger than it will plan, with a line saying so', function () {
    $a = simAutomation('lang');
    simStep($a, 't', 'manual');
    $previous = 't';

    foreach (range(1, 600) as $i) {
        simStep($a, "m$i", 'send_email', ['subject' => "Mail $i"]);
        simWire($a, $previous, "m$i");
        $previous = "m$i";
    }

    $flat = simEffects(simReact('lang'));

    expect($flat)->toContain('unknown:This automation is too large to plan further')
        ->and(count($flat))->toBeLessThan(600);
});

it('begins one way for each trigger when there is more than one', function () {
    $a = simAutomation('zwei-ausloeser');
    simStep($a, 't1', 'manual', []);
    simStep($a, 't2', 'manual', []);
    simStep($a, 'm', 'send_email', ['subject' => 'Gemeinsam']);
    simWire($a, 't1', 'm');
    simWire($a, 't2', 'm');

    $fork = simReact('zwei-ausloeser')[0];

    expect($fork->kind)->toBe(Effect::FORK)
        ->and($fork->children)->toHaveCount(2);
});

it('does not read a wait written as a variable as "right away"', function () {
    $a = simAutomation('variabel');
    simStep($a, 't', 'manual');
    simStep($a, 'w', 'delay', ['amount' => '{{ contact.wait }}', 'unit' => 'days']);
    simStep($a, 'm', 'send_email', ['subject' => 'Spaeter']);
    simLine($a, ['t', 'w', 'm']);

    $wait = simReact('variabel')[0];

    expect($wait->kind)->toBe(Effect::WAIT)
        ->and($wait->meta['duration_known'])->toBeFalse()
        ->and($wait->afterSeconds)->toBe(0);
});
