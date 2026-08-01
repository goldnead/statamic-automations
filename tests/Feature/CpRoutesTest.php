<?php

/**
 * End-to-end smoke test for the Statamic 6 Inertia CP layer.
 *
 * For each Automations CP page, this hits the route as an authenticated super
 * user and asserts:
 *   - The response is HTTP 200 (not 404, not 500).
 *   - It is an Inertia response (X-Inertia: true header).
 *   - The Inertia component identifier matches the expected Vue page.
 *
 * This is the test class that catches the "every CP page 500s" class of
 * regression — e.g. a route registered with a bare name instead of under the
 * `statamic.cp.` prefix, which would make every `cp_route()` call in the page
 * controllers throw a RouteNotFoundException.
 */

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

function inertiaComponent($response): ?string
{
    if (! $response->headers->get('X-Inertia')) {
        return null;
    }

    return json_decode($response->getContent(), true)['component'] ?? null;
}

it('renders the dashboard', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.dashboard'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Dashboard');
});

it('renders the automations index', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Automations/Index');
});

it('tells the empty state how many templates the registry actually has', function (): void {
    // The empty state used to promise "eight built-in patterns" in hardcoded
    // prose while eleven shipped. The count now comes from the registry, and
    // this is what keeps the two from drifting apart again.
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.index'));

    $props = json_decode($response->getContent(), true)['props'] ?? [];

    expect($props['templateCount'])
        ->toBe(count(app(TemplateRegistry::class)->all()))
        ->toBeGreaterThan(0);
});

it('renders the create (blank builder) page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.create'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Automations/Edit');
});

it('renders the automation builder for an existing automation', function (): void {
    $automation = Automation::create(['name' => 'Inquiry', 'handle' => 'inquiry']);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.edit', $automation));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Automations/Edit');
});

it('renders the runs index', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.runs.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Runs/Index');
});

it('renders a run detail page', function (): void {
    $automation = Automation::create(['name' => 'Inquiry', 'handle' => 'inquiry']);
    $run = AutomationRun::create([
        'automation_id' => $automation->id,
        'trigger_node_key' => 't',
        'trigger_type' => 'manual',
        'status' => 'success',
        'context' => [],
        'is_test' => false,
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.runs.show', $run));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Runs/Show');
});

it('renders the templates index', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.templates.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Templates/Index');
});

it('renders the import page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.import'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Import');
});

it('renders the settings page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.settings'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Settings/Show');
});

it('renders the audit log page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.audit'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('statamic-automations::Audit/Index');
});

it('blocks users without the view-automations permission', function (): void {
    $regular = User::make()->email('regular@example.com');
    $regular->save();

    $response = $this->actingAs($regular)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.index'));

    expect($response->getStatusCode())->toBeIn([302, 401, 403]);
});
