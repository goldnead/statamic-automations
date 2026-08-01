<?php

/**
 * Regression tests for the Automations index (list) page row actions.
 *
 * The Vue page (resources/js/pages/Automations/Index.vue) builds every row
 * action URL as `apiBase + '/automations/' + row.id`, where both `apiBase`
 * and `rows` come from the Inertia props of the index page. These tests
 * therefore do NOT hand-craft the API URL — they render the real page,
 * read the real props, and issue the request exactly like the frontend does
 * (same URL construction, same XHR headers).
 *
 * Regression: the index page used to pass
 * `cp_route('statamic-automations.api.automations.index')` (= .../api/automations)
 * as `apiBase`, so every row action resolved to
 * `.../api/automations/automations/{id}` and 404ed.
 */

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

/**
 * Render the index page like the CP does and return its Inertia props.
 */
function indexPageProps($test): array
{
    $response = $test->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.index'));

    $response->assertStatus(200);

    return json_decode($response->getContent(), true)['props'] ?? [];
}

/**
 * The headers axios sends from the CP (XHR + JSON expectations).
 */
function frontendHeaders(): array
{
    return [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json, text/plain, */*',
    ];
}

it('deletes an automation from the index page exactly like the frontend does (database driver)', function (): void {
    $automation = Automation::create(['name' => 'New Lead Notification', 'handle' => 'new-lead-notification']);

    $props = indexPageProps($this);
    $row = collect($props['rows'])->firstWhere('handle', 'new-lead-notification');
    expect($row)->not->toBeNull();

    // Exactly what Index.vue does: axios.delete(apiBase + '/automations/' + row.id)
    $url = $props['apiBase'].'/automations/'.$row['id'];

    $response = $this->withHeaders(frontendHeaders())->delete($url);

    $response->assertOk();
    $response->assertJson(['ok' => true]);
    expect(Automation::query()->count())->toBe(0);
});

it('toggles enabled state from the index page exactly like the frontend does', function (): void {
    $automation = Automation::create(['name' => 'Toggler', 'handle' => 'toggler', 'enabled' => true]);

    $props = indexPageProps($this);
    $row = collect($props['rows'])->firstWhere('handle', 'toggler');

    // Index.vue: axios.post(apiBase + '/automations/' + row.id + '/disable')
    $url = $props['apiBase'].'/automations/'.$row['id'].($row['enabled'] ? '/disable' : '/enable');

    $response = $this->withHeaders(frontendHeaders())->post($url);

    $response->assertOk();
    expect($automation->fresh()->enabled)->toBeFalse();
});

it('duplicates an automation from the index page exactly like the frontend does', function (): void {
    Automation::create(['name' => 'Original', 'handle' => 'original']);

    $props = indexPageProps($this);
    $row = collect($props['rows'])->firstWhere('handle', 'original');

    // Index.vue: axios.post(apiBase + '/automations/' + row.id + '/duplicate')
    $url = $props['apiBase'].'/automations/'.$row['id'].'/duplicate';

    $response = $this->withHeaders(frontendHeaders())->post($url);

    $response->assertCreated();
    expect(Automation::query()->count())->toBe(2);
});

it('exports an automation from the index page exactly like the frontend does', function (): void {
    Automation::create(['name' => 'Exportable', 'handle' => 'exportable']);

    $props = indexPageProps($this);
    $row = collect($props['rows'])->firstWhere('handle', 'exportable');

    // Index.vue: window.open(apiBase + '/automations/' + row.id + '/export')
    $url = $props['apiBase'].'/automations/'.$row['id'].'/export';

    $response = $this->get($url);

    $response->assertOk();
});

it('deletes an automation from the index page with the flat-file driver', function (): void {
    $dir = sys_get_temp_dir().'/automations-flat-'.uniqid();
    config()->set('automations.storage.driver', 'flat_file');
    config()->set('automations.storage.flat_file.path', $dir);
    app()->forgetInstance(AutomationRepository::class);
    app()->forgetInstance(WorkflowRunner::class);

    try {
        app(AutomationRepository::class)->save(
            new Automation(['name' => 'Flat Delete', 'handle' => 'flat-delete']),
        );
        expect(File::exists($dir.'/flat-delete.yaml'))->toBeTrue();

        $props = indexPageProps($this);
        $row = collect($props['rows'])->firstWhere('handle', 'flat-delete');
        expect($row)->not->toBeNull();

        $url = $props['apiBase'].'/automations/'.$row['id'];

        $response = $this->withHeaders(frontendHeaders())->delete($url);

        $response->assertOk();
        expect(File::exists($dir.'/flat-delete.yaml'))->toBeFalse();
    } finally {
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
});
