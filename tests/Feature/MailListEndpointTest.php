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

/**
 * ── The action endpoint ───────────────────────────────────────────────────
 *
 * The mail table is a Statamic `Listing`, and Statamic ties its checkbox column
 * to an action endpoint: no endpoint, no checkboxes. These two routes are that
 * endpoint — `/actions/list` says what a selection may do, `/actions` runs it —
 * so a multi-select in the CP deletes mails through the same ChainEditor as a
 * single one, with the same version snapshot in front of it.
 */
it('offers a delete for a selection of mails', function (): void {
    $automation = ($this->series)();

    $response = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', $automation),
        ['selections' => ['m1', 'm2']],
    );

    $response->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.handle'))->toBe('delete')
        ->and($response->json('0.dangerous'))->toBeTrue()
        // Statamic's runner only asks before an action whose `confirm` is on,
        // and a bulk delete that does not ask is the one this list must not be.
        ->and($response->json('0.confirm'))->toBeTrue()
        ->and($response->json('0.confirmationText'))->toContain('2');
});

it('offers no action at all against a list that cannot be edited', function (): void {
    // A branched flow has no editable list, so a toolbar here would carry one
    // button that always fails. An empty answer is the honest one.
    $automation = ($this->series)();
    $automation->nodes()->create(['node_key' => 'br', 'type' => 'branch', 'position_x' => 0, 'position_y' => 0, 'config' => []]);
    $automation->edges()->create(['from_node_key' => 'br', 'to_node_key' => 'm1', 'from_output' => 'true']);
    $automation->edges()->create(['from_node_key' => 'br', 'to_node_key' => 'm2', 'from_output' => 'false']);

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', $automation->fresh(['nodes', 'edges'])),
        ['selections' => ['m1']],
    )->assertOk()->assertExactJson([]);
});

it('offers nothing for an empty selection', function (): void {
    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', ($this->series)()),
        ['selections' => []],
    )->assertOk()->assertExactJson([]);
});

it('deletes every selected mail, and the gap in front of each', function (): void {
    $automation = ($this->series)();
    $before = count(app(VersionManager::class)->versions($automation));

    $response = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions', $automation),
        ['action' => 'delete', 'selections' => ['m1', 'm3']],
    );

    $response->assertOk();
    // Statamic's action runner shows `message` as the toast and then refreshes;
    // returning the projection here would be shown as "Action completed".
    expect($response->json('message'))->toContain('2');

    $automation = $automation->fresh(['nodes', 'edges']);
    $keys = $automation->nodes->pluck('node_key')->all();

    expect($keys)->toContain('t')
        ->and($keys)->toContain('m2')
        ->and($keys)->not->toContain('m1')
        ->and($keys)->not->toContain('m3')
        // The five-day wait belonged to the mail that is gone and went with it.
        ->and($keys)->not->toContain('d2')
        // One snapshot for the whole selection, not one per mail: an editor who
        // deletes two and reverts means the two.
        ->and(count(app(VersionManager::class)->versions($automation)))->toBe($before + 1);
});

it('refuses an action from somebody without the edit permission', function (): void {
    $automation = ($this->series)();

    $plain = User::make()->email('action-reader@example.com');
    $plain->save();

    $this->actingAs($plain);

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions', $automation),
        ['action' => 'delete', 'selections' => ['m1']],
    )->assertStatus(403);

    expect($automation->fresh(['nodes'])->nodes->pluck('node_key')->all())->toContain('m1');
});

it('runs no action it does not know', function (): void {
    $automation = ($this->series)();

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions', $automation),
        ['action' => 'publish', 'selections' => ['m1']],
    )->assertStatus(422);

    expect($automation->fresh(['nodes'])->nodes->pluck('node_key')->all())->toContain('m1');
});

it('offers nothing for a selection this list does not hold', function (): void {
    // The client picks the ids, and the table it picked them from may be
    // minutes old — a second tab, a colleague, an undo. Offering "delete"
    // against a key that is gone produces a button guaranteed to fail.
    $automation = ($this->series)();

    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', $automation),
        ['selections' => ['m1', 'gone']],
    )->assertOk()->assertExactJson([]);

    // `d1` is a delay, not a mail. The list holds mails.
    $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', $automation),
        ['selections' => ['d1']],
    )->assertOk()->assertExactJson([]);
});

it('writes no version for a delete it refuses', function (): void {
    // The bug this pins: `write()` used to snapshot BEFORE applying, so a
    // refused action left a "Removed mails from the list" version behind that
    // removed nothing — and VersionManager prunes to 25, so enough of them push
    // the real history out. `runAction` is the one write path where the client
    // chooses a list of ids, which makes a stale selection the ordinary case.
    $automation = ($this->series)();
    $before = count(app(VersionManager::class)->versions($automation));

    foreach ([['gone'], ['m1', 'gone'], ['d1'], ['t']] as $selections) {
        $response = $this->postJson(
            cp_route('statamic-automations.api.automations.mail-list.actions', $automation),
            ['action' => 'delete', 'selections' => $selections],
        );

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('no mail with the key');
        // The refusal carries the list as it still is, so the screen is
        // corrected rather than only complained at.
        expect($response->json('list.editable'))->toBeTrue();
    }

    expect(count(app(VersionManager::class)->versions($automation->fresh())))->toBe($before)
        // …and nothing was removed either.
        ->and($automation->fresh(['nodes'])->nodes->pluck('node_key')->all())
        ->toContain('m1')->toContain('d1')->toContain('t');
});

it('writes no version for a delete against a branched automation', function (): void {
    $automation = ($this->series)();
    $automation->nodes()->create(['node_key' => 'br', 'type' => 'branch', 'position_x' => 0, 'position_y' => 0, 'config' => []]);
    $automation->edges()->create(['from_node_key' => 'br', 'to_node_key' => 'm1', 'from_output' => 'true']);
    $automation->edges()->create(['from_node_key' => 'br', 'to_node_key' => 'm2', 'from_output' => 'false']);
    $automation = $automation->fresh(['nodes', 'edges']);

    $before = count(app(VersionManager::class)->versions($automation));

    $response = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions', $automation),
        ['action' => 'delete', 'selections' => ['m1']],
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('not a straight line')
        ->and(count(app(VersionManager::class)->versions($automation->fresh())))->toBe($before);
});

it('names the one mail it is about to delete', function (): void {
    // A reader can open the row menu on the wrong row, and the name is the only
    // thing on the dialog that says so. Several at once are counted instead —
    // there is no name that covers them.
    $automation = ($this->series)();

    $one = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', $automation),
        ['selections' => ['m2']],
    );

    expect($one->json('0.confirmationText'))->toContain('Two')
        // `title` is what the floating toolbar prints on its button, so it is
        // the string that has to count.
        ->and($one->json('0.title'))->toBe('Delete mail');

    $two = $this->postJson(
        cp_route('statamic-automations.api.automations.mail-list.actions.list', $automation),
        ['selections' => ['m1', 'm2']],
    );

    expect($two->json('0.title'))->toBe('Delete 2 mails')
        ->and($two->json('0.buttonText'))->toBe('Delete 2 mails');
});
