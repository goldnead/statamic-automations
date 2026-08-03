<?php

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Statamic\Facades\User;

/**
 * The mail list over HTTP.
 *
 * Reading works for every automation. Writing works only while the graph is a
 * straight line, and a write against anything else answers 422 with the rule's
 * own reasons — an editor who is told WHY the list is locked can decide what to
 * do; one who is told nothing files a bug.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->series = function (): Automation {
        $automation = Automation::create(['name' => 'Welcome', 'handle' => 'welcome', 'enabled' => true]);

        foreach ([
            ['t', 'manual', []],
            ['m1', 'send_email', ['subject' => 'One', 'to' => 'a@b.c', 'body' => 'x']],
            ['d1', 'delay', ['amount' => 2, 'unit' => 'days']],
            ['m2', 'send_email', ['subject' => 'Two', 'to' => 'a@b.c', 'body' => 'x']],
            ['d2', 'delay', ['amount' => 5, 'unit' => 'days']],
            ['m3', 'send_email', ['subject' => 'Three', 'to' => 'a@b.c', 'body' => 'x']],
        ] as [$key, $type, $config]) {
            $automation->nodes()->create([
                'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config,
            ]);
        }

        foreach ([['t', 'm1'], ['m1', 'd1'], ['d1', 'm2'], ['m2', 'd2'], ['d2', 'm3']] as [$from, $to]) {
            $automation->edges()->create(['from_node_key' => $from, 'to_node_key' => $to, 'from_output' => 'default']);
        }

        return $automation->fresh(['nodes', 'edges']);
    };

    $this->url = fn (Automation $a, string $suffix = '') => cp_route('statamic-automations.api.automations.mail-list', $a).$suffix;
});

it('returns the list of mails with the gap before each one', function (): void {
    $response = $this->getJson(($this->url)(($this->series)()));

    $response->assertOk();

    expect($response->json('editable'))->toBeTrue()
        ->and(array_column($response->json('mails'), 'label'))->toBe(['One', 'Two', 'Three'])
        ->and(array_map(fn ($m) => $m['delay']['seconds'], $response->json('mails')))
        ->toBe([0, 2 * 86400, 5 * 86400]);
});

it('reorders the mails and takes each gap with its mail', function (): void {
    $automation = ($this->series)();

    $response = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.reorder', $automation),
        ['order' => ['m3', 'm1', 'm2']],
    );

    $response->assertOk();

    expect(array_column($response->json('mails'), 'node_key'))->toBe(['m3', 'm1', 'm2'])
        // The five-day wait belonged to mail three and moved with it; mail one
        // now has no gap in front of it because it never had one of its own.
        ->and(array_map(fn ($m) => $m['delay']['seconds'], $response->json('mails')))
        ->toBe([5 * 86400, 0, 2 * 86400]);

    // The graph agrees, not just the projection.
    $automation = $automation->fresh(['nodes', 'edges']);
    $chain = $automation->edges->mapWithKeys(fn ($e) => [$e->from_node_key => $e->to_node_key]);

    expect($chain['t'])->toBe('d2')
        ->and($chain['d2'])->toBe('m3')
        ->and($chain['m3'])->toBe('m1');
});

it('refuses a reorder that is not the same set of mails', function (): void {
    $automation = ($this->series)();

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.reorder', $automation),
        ['order' => ['m1', 'm2']],
    )->assertStatus(422);

    // …and changed nothing.
    expect($automation->fresh(['nodes', 'edges'])->edges()->count())->toBe(5);
});

it('deletes a mail together with the gap that preceded it', function (): void {
    $automation = ($this->series)();

    $response = $this->deleteJson(($this->url)($automation).'/m2');

    $response->assertOk();

    expect(array_column($response->json('mails'), 'node_key'))->toBe(['m1', 'm3']);

    $keys = $automation->fresh(['nodes', 'edges'])->nodes->pluck('node_key')->all();

    expect($keys)->not->toContain('m2')
        // The two-day wait was the gap before mail two and means nothing without it.
        ->and($keys)->not->toContain('d1')
        ->and($keys)->toContain('d2');
});

it('keeps the work around a deleted mail', function (): void {
    // Deleting a mail is not permission to delete the tag somebody put in
    // front of it.
    $automation = Automation::create(['name' => 'W', 'handle' => 'w2', 'enabled' => true]);

    foreach ([
        ['t', 'manual', []],
        ['log', 'add_log_entry', ['message' => 'about to mail']],
        ['m1', 'send_email', ['subject' => 'One', 'to' => 'a@b.c', 'body' => 'x']],
        ['m2', 'send_email', ['subject' => 'Two', 'to' => 'a@b.c', 'body' => 'x']],
    ] as [$key, $type, $config]) {
        $automation->nodes()->create(['node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config]);
    }

    foreach ([['t', 'log'], ['log', 'm1'], ['m1', 'm2']] as [$from, $to]) {
        $automation->edges()->create(['from_node_key' => $from, 'to_node_key' => $to, 'from_output' => 'default']);
    }

    $this->deleteJson(($this->url)($automation).'/m1')->assertOk();

    $automation = $automation->fresh(['nodes', 'edges']);

    expect($automation->nodes->pluck('node_key')->all())->toContain('log')
        ->and($automation->edges->firstWhere('from_node_key', 'log')->to_node_key)->toBe('m2');
});

it('inserts a mail with its own gap, at the requested position', function (): void {
    $automation = ($this->series)();

    $response = $this->postJson(($this->url)($automation), [
        'type' => 'send_email',
        'after' => 'm1',
        'config' => ['subject' => 'Inserted', 'to' => 'a@b.c', 'body' => 'x'],
        'delay' => ['amount' => 1, 'unit' => 'days'],
    ]);

    $response->assertOk();

    expect(array_column($response->json('mails'), 'label'))->toBe(['One', 'Inserted', 'Two', 'Three'])
        ->and($response->json('mails.1.delay.seconds'))->toBe(86400)
        // The mail that used to follow keeps its own gap.
        ->and($response->json('mails.2.delay.seconds'))->toBe(2 * 86400);
});

it('shows the list of a branched automation but refuses to edit it', function (): void {
    $automation = Automation::create(['name' => 'B', 'handle' => 'branched', 'enabled' => true]);

    foreach ([
        ['t', 'manual', []],
        ['m1', 'send_email', ['subject' => 'One', 'to' => 'a@b.c', 'body' => 'x']],
        ['b', 'branch', []],
        ['yes', 'send_email', ['subject' => 'Yes', 'to' => 'a@b.c', 'body' => 'x']],
        ['no', 'send_email', ['subject' => 'No', 'to' => 'a@b.c', 'body' => 'x']],
    ] as [$key, $type, $config]) {
        $automation->nodes()->create(['node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config]);
    }

    foreach ([['t', 'm1', 'default'], ['m1', 'b', 'default'], ['b', 'yes', 'true'], ['b', 'no', 'false']] as [$from, $to, $out]) {
        $automation->edges()->create(['from_node_key' => $from, 'to_node_key' => $to, 'from_output' => $out]);
    }

    $read = $this->getJson(($this->url)($automation));

    $read->assertOk();

    expect($read->json('editable'))->toBeFalse()
        ->and(count($read->json('mails')))->toBe(3)
        ->and($read->json('reasons'))->not->toBe([]);

    $write = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.reorder', $automation),
        ['order' => ['no', 'yes', 'm1']],
    );

    $write->assertStatus(422);

    expect($write->json('message'))->toContain('not a straight line')
        // The list comes back with the refusal, so the screen can stay useful.
        ->and($write->json('list.editable'))->toBeFalse();

    // Nothing moved.
    expect($automation->fresh(['nodes', 'edges'])->edges->firstWhere('from_node_key', 't')->to_node_key)->toBe('m1');
});

it('refuses a write to somebody without the edit permission', function (): void {
    // Every CP write route in this addon has one of these. A list that edited
    // the graph without asking would be the one screen that did not.
    $automation = ($this->series)();

    $plain = User::make()->email('reader@example.com');
    $plain->save();

    $this->actingAs($plain);

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.reorder', $automation),
        ['order' => ['m1', 'm2', 'm3']],
    )->assertStatus(403);
});

it('snapshots a version before it rewrites the chain', function (): void {
    // An edit made from a list is still an edit to the graph, and has to be
    // revertable from the same history as one made on the canvas.
    $automation = ($this->series)();

    $before = count(app(VersionManager::class)->versions($automation));

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.reorder', $automation),
        ['order' => ['m2', 'm1', 'm3']],
    )->assertOk();

    expect(count(app(VersionManager::class)->versions($automation->fresh())))
        ->toBeGreaterThan($before);
});

it('never lets the trigger be reordered away', function (): void {
    $automation = ($this->series)();

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.reorder', $automation),
        ['order' => ['m3', 'm2', 'm1']],
    )->assertOk();

    $automation = $automation->fresh(['nodes', 'edges']);

    expect(AutomationNode::query()->where('automation_id', $automation->id)->where('node_key', 't')->exists())->toBeTrue()
        ->and($automation->edges->where('to_node_key', 't')->count())->toBe(0)
        ->and($automation->edges->where('from_node_key', 't')->count())->toBe(1);
});
