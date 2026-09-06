<?php

/**
 * Smoke coverage for the JSON API endpoints the Vue builder hits via axios.
 *
 * Every one of these is reachable from the CP frontend, so a 500 here means a
 * broken builder/list/settings screen in a real Control Panel. We assert each
 * endpoint resolves and returns a non-error status with the expected shape.
 */

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

function makeAutomationWithRun(): array
{
    $automation = Automation::create(['name' => 'Inquiry', 'handle' => 'inquiry']);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => 'manual',
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => 'hi'],
    ]);
    AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 't',
        'to_node_key' => 'log',
    ]);

    $run = AutomationRun::create([
        'automation_id' => $automation->id,
        'trigger_node_key' => 't',
        'trigger_type' => 'manual',
        'status' => 'success',
        'context' => [],
        'is_test' => false,
    ]);

    return [$automation, $run];
}

it('serves the api ping endpoint', function (): void {
    $this->getJson('/cp/automations/api')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('lists automations', function (): void {
    makeAutomationWithRun();

    $this->getJson('/cp/automations/api/automations')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows a single automation', function (): void {
    [$automation] = makeAutomationWithRun();

    $this->getJson("/cp/automations/api/automations/{$automation->id}")
        ->assertOk()
        ->assertJsonPath('data.handle', 'inquiry');
});

it('lists triggers and actions metadata', function (): void {
    $this->getJson('/cp/automations/api/triggers')->assertOk();
    $this->getJson('/cp/automations/api/actions')->assertOk();
});

it('describes a single node', function (): void {
    $this->getJson('/cp/automations/api/nodes/manual')
        ->assertOk()
        ->assertJsonPath('data.handle', 'manual');
});

it('lists runs and shows a run detail', function (): void {
    [, $run] = makeAutomationWithRun();

    $this->getJson('/cp/automations/api/runs')->assertOk();

    $this->getJson("/cp/automations/api/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $run->id);
});

it('no longer serves a settings endpoint', function (): void {
    // Both `api/settings` routes went with the screen they fed. Reading and
    // writing settings is brand-context's `brand-context.settings.*` since
    // 2026-09-06, and that screen posts through Inertia, not axios. Asserted
    // rather than deleted: a 404 here is the correct answer, and an endpoint
    // that quietly came back would be a second way to write the same rows.
    $this->getJson('/cp/automations/api/settings')->assertNotFound();
    $this->patchJson('/cp/automations/api/settings', ['settings' => []])->assertNotFound();
});

it('enables and disables an automation', function (): void {
    [$automation] = makeAutomationWithRun();

    $this->postJson("/cp/automations/api/automations/{$automation->id}/enable")->assertOk();
    expect($automation->fresh()->enabled)->toBeTrue();

    $this->postJson("/cp/automations/api/automations/{$automation->id}/disable")->assertOk();
    expect($automation->fresh()->enabled)->toBeFalse();
});

it('duplicates an automation', function (): void {
    [$automation] = makeAutomationWithRun();

    $this->postJson("/cp/automations/api/automations/{$automation->id}/duplicate")
        ->assertCreated();

    expect(Automation::count())->toBe(2);
});

it('exports an automation as json', function (): void {
    [$automation] = makeAutomationWithRun();

    $response = $this->get("/cp/automations/api/automations/{$automation->id}/export");

    $response->assertOk();
    expect(json_decode($response->getContent(), true))->toBeArray();
});

it('deletes an automation', function (): void {
    [$automation] = makeAutomationWithRun();

    $this->deleteJson("/cp/automations/api/automations/{$automation->id}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Automation::count())->toBe(0);
});
