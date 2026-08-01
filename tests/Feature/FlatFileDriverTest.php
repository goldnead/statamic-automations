<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Repositories\FlatFileAutomationRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/automations-flat-'.uniqid();
    config()->set('automations.storage.driver', 'flat_file');
    config()->set('automations.storage.flat_file.path', $this->dir);

    // Rebuild driver-dependent singletons so they pick up the flat-file driver.
    app()->forgetInstance(AutomationRepository::class);
    app()->forgetInstance(WorkflowRunner::class);
});

afterEach(function () {
    if (is_dir($this->dir)) {
        File::deleteDirectory($this->dir);
    }
});

function flatRepo(): AutomationRepository
{
    return app(AutomationRepository::class);
}

it('binds the flat-file driver from config', function () {
    expect(flatRepo())->toBeInstanceOf(FlatFileAutomationRepository::class);
});

it('writes a definition to a YAML file and reads it back', function () {
    $automation = new Automation(['name' => 'Flat One', 'handle' => 'flat-one', 'enabled' => true]);

    $saved = flatRepo()->save(
        $automation,
        [
            ['node_key' => 't', 'type' => 'manual'],
            ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'hi']],
        ],
        [['from_node_key' => 't', 'to_node_key' => 'log']],
    );

    expect(File::exists($this->dir.'/flat-one.yaml'))->toBeTrue();

    $loaded = flatRepo()->findByRef('flat-one');
    expect($loaded)->not->toBeNull();
    expect($loaded->name)->toBe('Flat One');
    expect($loaded->nodes)->toHaveCount(2);
    expect($loaded->edges)->toHaveCount(1);
    expect($loaded->exists)->toBeFalse();
    // No database row was created.
    expect(Automation::query()->count())->toBe(0);
});

it('lists and counts flat-file definitions', function () {
    flatRepo()->save(new Automation(['name' => 'A', 'handle' => 'a', 'enabled' => true]), [['node_key' => 't', 'type' => 'manual']], []);
    flatRepo()->save(new Automation(['name' => 'B', 'handle' => 'b', 'enabled' => false]), [['node_key' => 't', 'type' => 'manual']], []);

    expect(flatRepo()->count())->toBe(2);
    expect(flatRepo()->enabledCount())->toBe(1);
    expect(flatRepo()->enabled())->toHaveCount(1);
});

it('runs a flat-file automation end-to-end with runtime data in the DB', function () {
    flatRepo()->save(
        new Automation(['name' => 'Runner', 'handle' => 'runner', 'enabled' => true]),
        [
            ['node_key' => 't', 'type' => 'manual'],
            ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'ran']],
        ],
        [['from_node_key' => 't', 'to_node_key' => 'log']],
    );

    $automation = flatRepo()->findByRef('runner');
    $runner = app(WorkflowRunner::class);
    $context = AutomationContext::make([]);

    $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
    // The run links to the flat-file definition by uuid, not a FK.
    expect($run->automation_id)->toBeNull();
    expect($run->automation_uuid)->toBe($automation->uuid);

    $final = $runner->execute($run, $context);

    expect($final->status)->toBe(AutomationRun::STATUS_SUCCESS);
    expect($final->nodeRuns()->where('node_key', 'log')->exists())->toBeTrue();
});

it('renders the CP list and dashboard from flat files', function () {
    $this->actingAsSuperUser();

    flatRepo()->save(
        new Automation(['name' => 'Visible Flat', 'handle' => 'visible-flat', 'enabled' => true]),
        [['node_key' => 't', 'type' => 'manual']],
        [],
    );

    $index = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.index'));
    $index->assertStatus(200);
    expect($index->getContent())->toContain('Visible Flat');

    $dashboard = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.dashboard'));
    $dashboard->assertStatus(200);
});

it('resolves the {automation} route binding from a flat file', function () {
    $this->actingAsSuperUser();

    $saved = flatRepo()->save(
        new Automation(['name' => 'Bind Me', 'handle' => 'bind-me', 'enabled' => true]),
        [['node_key' => 't', 'type' => 'manual']],
        [],
    );

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.edit', $saved->id));

    $response->assertStatus(200);
    expect($response->getContent())->toContain('Bind Me');
});

it('lists and searches flat-file definitions via the JSON API', function () {
    $this->actingAsSuperUser();

    flatRepo()->save(new Automation(['name' => 'Alpha Flow', 'handle' => 'alpha', 'enabled' => true]), [['node_key' => 't', 'type' => 'manual']], []);
    flatRepo()->save(new Automation(['name' => 'Beta Flow', 'handle' => 'beta', 'enabled' => true]), [['node_key' => 't', 'type' => 'manual']], []);

    $all = $this->getJson('/cp/automations/api/automations');
    $all->assertOk()->assertJsonCount(2, 'data');

    $search = $this->getJson('/cp/automations/api/automations?search=beta');
    $search->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.handle', 'beta');
});

it('deletes a flat-file definition', function () {
    flatRepo()->save(new Automation(['name' => 'Del', 'handle' => 'del']), [['node_key' => 't', 'type' => 'manual']], []);
    expect(File::exists($this->dir.'/del.yaml'))->toBeTrue();

    flatRepo()->delete(flatRepo()->findByRef('del'));
    expect(File::exists($this->dir.'/del.yaml'))->toBeFalse();
});
