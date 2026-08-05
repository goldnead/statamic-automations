<?php

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Statamic\Facades\User;

/**
 * The rule row over HTTP.
 *
 * Same split as the mail list: reading works for every automation, writing only
 * while the automation is a rule, and a write against anything else answers 422
 * with the shape's own reasons — plus the row, so the screen stays useful
 * instead of going blank on a refusal.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->rule = function (string $handle = 'contact-reply'): Automation {
        $automation = Automation::create(['name' => 'Contact reply', 'handle' => $handle, 'enabled' => true]);

        foreach ([
            ['t', 'manual', []],
            ['m', 'send_email', ['subject' => 'Thanks', 'to' => 'hallo@example.com', 'body' => 'x']],
        ] as [$key, $type, $config]) {
            $automation->nodes()->create([
                'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config,
            ]);
        }

        $automation->edges()->create(['from_node_key' => 't', 'to_node_key' => 'm', 'from_output' => 'default']);

        return $automation->fresh(['nodes', 'edges']);
    };

    $this->show = fn (Automation $a) => cp_route('statamic-automations.api.automations.rule', $a);
    $this->update = fn (Automation $a) => cp_route('statamic-automations.api.automations.rule.update', $a);
});

it('reads the rule as one row', function (): void {
    $response = $this->getJson(($this->show)(($this->rule)()));

    $response->assertOk();

    expect($response->json('trigger.handle'))->toBe('manual')
        ->and($response->json('recipient'))->toBe('hallo@example.com')
        ->and($response->json('dispatch_mode'))->toBe('async')
        ->and($response->json('editable'))->toBeTrue();
});

it('writes the row back and answers with it', function (): void {
    $automation = ($this->rule)();

    $response = $this->patchJson(($this->update)($automation), [
        'recipient' => 'team@example.com',
        'dispatch_mode' => 'sync',
        'enabled' => false,
    ]);

    $response->assertOk();

    expect($response->json('recipient'))->toBe('team@example.com')
        ->and($response->json('dispatch_mode'))->toBe('sync')
        ->and($response->json('enabled'))->toBeFalse();

    // The graph agrees, not just the answer.
    $automation = $automation->fresh(['nodes', 'edges']);

    expect($automation->nodes->firstWhere('node_key', 'm')->config['to'])->toBe('team@example.com')
        ->and($automation->nodes->firstWhere('node_key', 't')->config[DispatchMode::CONFIG_KEY])->toBe('sync');
});

it('shows a shape it cannot edit but refuses to write it', function (): void {
    $automation = ($this->rule)('with-delay');

    $automation->nodes()->create([
        'node_key' => 'd', 'type' => 'delay', 'position_x' => 0, 'position_y' => 0,
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    $automation->edges()->delete();
    $automation->edges()->create(['from_node_key' => 't', 'to_node_key' => 'd', 'from_output' => 'default']);
    $automation->edges()->create(['from_node_key' => 'd', 'to_node_key' => 'm', 'from_output' => 'default']);

    $read = $this->getJson(($this->show)($automation));

    $read->assertOk();

    expect($read->json('editable'))->toBeFalse()
        ->and($read->json('recipient'))->toBe('hallo@example.com')
        ->and($read->json('reasons'))->not->toBe([]);

    $write = $this->patchJson(($this->update)($automation), ['recipient' => 'team@example.com']);

    $write->assertStatus(422);

    expect($write->json('message'))->toContain('not a rule')
        // The row comes back with the refusal, so the screen can stay useful.
        ->and($write->json('rule.editable'))->toBeFalse();

    // Nothing was written.
    expect($automation->fresh(['nodes', 'edges'])->nodes->firstWhere('node_key', 'm')->config['to'])
        ->toBe('hallo@example.com');
});

it('refuses a dispatch mode that is not one', function (): void {
    $this->patchJson(($this->update)(($this->rule)()), ['dispatch_mode' => 'vielleicht'])
        ->assertStatus(422);
});

it('refuses a write to somebody without the edit permission', function (): void {
    $automation = ($this->rule)();

    $plain = User::make()->email('reader@example.com');
    $plain->save();

    $this->actingAs($plain);

    $this->patchJson(($this->update)($automation), ['recipient' => 'team@example.com'])
        ->assertStatus(403);
});

it('snapshots a version before it writes', function (): void {
    // An edit made from a row is still an edit to the graph, and has to be
    // revertable from the same history as one made on the canvas.
    $automation = ($this->rule)();

    $before = count(app(VersionManager::class)->versions($automation));

    $this->patchJson(($this->update)($automation), ['recipient' => 'team@example.com'])->assertOk();

    expect(count(app(VersionManager::class)->versions($automation->fresh())))->toBeGreaterThan($before);
});
