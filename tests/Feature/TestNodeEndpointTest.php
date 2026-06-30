<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

it('runs a single node in isolation in test mode', function () {
    $automation = Automation::create(['name' => 'T', 'handle' => 't']);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'tr', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'sv', 'type' => 'set_variable',
        'config' => ['variables' => ['greeting' => 'hi {{ name }}']],
    ]);

    $response = $this->postJson(
        cp_route('statamic-automations.api.automations.test-node', $automation),
        ['node_key' => 'sv', 'context' => ['name' => 'Ada']]
    );

    $response->assertStatus(200);
    expect($response->json('ok'))->toBeTrue();
    expect($response->json('status'))->toBe('success');
    expect($response->json('output.vars.greeting'))->toBe('hi Ada');
});

it('404s for an unknown node', function () {
    $automation = Automation::create(['name' => 'T2', 'handle' => 't2']);

    $response = $this->postJson(
        cp_route('statamic-automations.api.automations.test-node', $automation),
        ['node_key' => 'missing']
    );

    $response->assertStatus(404);
});
