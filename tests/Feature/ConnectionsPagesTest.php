<?php

// The CP pages of connections: the listing, and one page that creates or
// edits a connection with its operations. They hand the Vue pages what the
// JSON API would, through the same presenter — so the one rule that matters
// most here is the API's: a stored credential never reaches the browser.

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Statamic\Facades\Role;
use Statamic\Facades\User;

const PAGE_SECRET = 'sk-page-9d2b4e7a';

beforeEach(function (): void {
    $this->inertia = function (string $url): array {
        $response = $this->withHeaders(['X-Inertia' => 'true'])->get($url);
        $response->assertOk();

        expect($response->getContent())->not->toContain(PAGE_SECRET);

        return json_decode($response->getContent(), true);
    };

    $this->connection = AutomationConnection::create([
        'handle' => 'crm',
        'name' => 'CRM',
        'base_url' => 'https://api.example.test',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => PAGE_SECRET],
    ]);

    $this->connection->operations()->create([
        'handle' => 'create_contact',
        'name' => 'Create contact',
        'method' => 'POST',
        'path' => '/contacts',
    ]);
});

it('renders the listing with a row per connection and no credential', function () {
    $this->actingAsSuperUser();

    $page = ($this->inertia)(cp_route('statamic-automations.connections.index'));

    expect($page['component'])->toBe('statamic-automations::Connections/Index');

    $props = $page['props'];
    expect(collect($props['columns'])->pluck('field')->all())
        ->toBe(['name', 'base_url', 'auth_type', 'operations_count'])
        ->and($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0])->toMatchArray([
            'handle' => 'crm',
            'name' => 'CRM',
            'base_url' => 'https://api.example.test',
            'auth_type' => 'bearer',
            'operations_count' => 1,
            'edit_url' => cp_route('statamic-automations.connections.edit', $this->connection->id),
        ])
        ->and($props['rows'][0]['auth_config'])->toBe(['token' => '••••••••'])
        ->and($props['createUrl'])->toBe(cp_route('statamic-automations.connections.create'))
        ->and(array_keys($props['authTypes']))->toBe(AutomationConnection::AUTH_TYPES);
});

it('renders the create page as a new, empty connection', function () {
    $this->actingAsSuperUser();

    $page = ($this->inertia)(cp_route('statamic-automations.connections.create'));

    expect($page['component'])->toBe('statamic-automations::Connections/Edit')
        ->and($page['props']['isNew'])->toBeTrue()
        ->and($page['props']['connection']['id'])->toBeNull()
        ->and($page['props']['connection']['auth_type'])->toBe('none')
        ->and($page['props']['operationsUrl'])->toBeNull()
        ->and($page['props']['storeUrl'])->toBe(cp_route('statamic-automations.api.connections.store'))
        ->and($page['props']['authFields'])->toBe(AutomationConnection::AUTH_FIELDS);
});

it('renders the edit page with operations, who uses it, and placeholders only', function () {
    $this->actingAsSuperUser();

    $automation = Automation::create(['name' => 'Lead to CRM', 'handle' => 'lead_to_crm']);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'op',
        'type' => 'connection.crm.create_contact',
        'config' => [],
    ]);

    $page = ($this->inertia)(cp_route('statamic-automations.connections.edit', $this->connection->id));

    expect($page['component'])->toBe('statamic-automations::Connections/Edit');

    $props = $page['props'];
    expect($props['isNew'])->toBeFalse()
        ->and($props['title'])->toBe('CRM')
        ->and($props['connection']['auth_config'])->toBe(['token' => '••••••••'])
        ->and($props['connection']['auth_configured'])->toBeTrue()
        ->and($props['connection']['operations'])->toHaveCount(1)
        ->and($props['connection']['operations'][0]['node_type'])->toBe('connection.crm.create_contact')
        ->and($props['connection']['used_by'])->toBe([
            ['id' => $automation->id, 'name' => 'Lead to CRM', 'handle' => 'lead_to_crm'],
        ])
        ->and($props['operationsUrl'])->toBe(
            cp_route('statamic-automations.api.connections.operations.store', $this->connection->id),
        )
        ->and($props['placeholder'])->toBe('••••••••');
});

it('answers 404 for a connection that does not exist', function () {
    $this->actingAsSuperUser();

    $this->get(cp_route('statamic-automations.connections.edit', 999999))->assertNotFound();
});

it('keeps all three pages behind the connections permission', function (string $route, bool $withId) {
    $role = Role::make('automation-editor')->title('Automation editor')
        ->addPermission(['access cp', 'view automations', 'edit automations']);
    Role::save($role);

    $user = User::make()->email('editor@example.com');
    $user->assignRole('automation-editor');
    $user->save();

    $this->actingAs($user);

    $url = $withId
        ? cp_route($route, $this->connection->id)
        : cp_route($route);

    // Statamic answers a refused CP page with a redirect back and a toast,
    // and a request that asks for JSON with the 403 itself.
    $this->get($url)->assertRedirect();
    $this->getJson($url)->assertForbidden();
})->with([
    'listing' => ['statamic-automations.connections.index', false],
    'create' => ['statamic-automations.connections.create', false],
    'edit' => ['statamic-automations.connections.edit', true],
]);
