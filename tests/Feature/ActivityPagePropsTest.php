<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * What the builder page hands the activity view before anything is clicked.
 *
 * The numbers travel as a prop rather than being fetched, because the canvas
 * paints them on its cards the moment the page opens. Everything that comes
 * after a click is ActivityController's, and has its own suite next door.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->automation = Automation::create(['name' => 'Series', 'handle' => 'series']);

    foreach ([['trigger', 'manual'], ['welcome', 'send_email'], ['reminder', 'send_email']] as [$key, $type]) {
        $this->automation->nodes()->create([
            'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => [],
        ]);
    }

    $this->props = function (): array {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('statamic-automations.automations.edit', $this->automation->id));

        $response->assertOk();

        return json_decode($response->getContent(), true)['props'] ?? [];
    };

    $this->run = function (array $attributes = []): AutomationRun {
        return AutomationRun::create(array_merge([
            'automation_id' => $this->automation->id,
            'automation_uuid' => $this->automation->uuid,
            'status' => AutomationRun::STATUS_SUCCESS,
        ], $attributes));
    };

    $this->step = function (AutomationRun $run, string $key, string $status = AutomationNodeRun::STATUS_SUCCESS): void {
        AutomationNodeRun::create([
            'automation_run_id' => $run->id,
            'node_key' => $key,
            'node_type' => 'send_email',
            'status' => $status,
        ]);
    };
});

it('renders an automation that has never run, with no numbers to divide by', function (): void {
    // The one shape that breaks a funnel: nothing to be a percentage of. The
    // node map is empty rather than three rows of zeroes, and the funnel is
    // five honest zeroes.
    $activity = ($this->props)()['activity'];

    expect($activity['nodes'])->toBe([])
        ->and($activity['funnel'])->toBe([
            'enrolled' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'exited' => 0,
            'failed' => 0,
        ])
        ->and($activity['without_subject'])->toBe(0)
        ->and($activity['range'])->toBe('30');
});

it('hands the canvas one entry per step that has actually run', function (): void {
    $run = ($this->run)();
    ($this->step)($run, 'trigger');
    ($this->step)($run, 'welcome');

    $nodes = ($this->props)()['activity']['nodes'];

    expect($nodes)->toHaveKey('trigger')
        ->and($nodes)->toHaveKey('welcome')
        // On the canvas, absent means the card shows nothing. A step in the
        // graph that nobody has reached must not read "0".
        ->and($nodes)->not->toHaveKey('reminder');
});

it('leaves test runs out of the numbers the canvas paints', function (): void {
    ($this->step)(($this->run)(['is_test' => true]), 'welcome');

    expect(($this->props)()['activity']['nodes'])->toBe([]);
});

it('carries the urls, the ranges and the columns the view needs', function (): void {
    $activity = ($this->props)()['activity'];

    expect(array_column($activity['ranges'], 'value'))->toBe(['7', '30', '90', 'all'])
        ->and($activity['logUrl'])->toContain('/activity/node-runs')
        ->and($activity['subjectsUrl'])->toContain('/activity/subjects')
        ->and($activity['exportUrl'])->toContain('/activity/export')
        ->and(array_column($activity['logColumns'], 'field'))
        ->toBe(['created_at', 'node_label', 'node_type', 'status', 'subject', 'duration_ms', 'error_message'])
        ->and(array_column($activity['subjectColumns'], 'field'))
        ->toBe(['subject', 'node_label', 'status', 'enrolled_at']);
});

it('withholds the whole view from somebody who may not read runs', function (): void {
    // The view is a reading of runs, so it is gated on the run permission and
    // not on the one that got them onto the builder page. Withheld as a null
    // prop rather than an empty one: the switcher then does not offer a third
    // view at all, instead of offering one that answers 403.
    $role = Role::make('flow-editor')->title('Flow editor')
        ->addPermission(['access cp', 'view automations', 'edit automations']);
    Role::save($role);

    $user = User::make()->email('editor@example.com');
    $user->assignRole('flow-editor');
    $user->save();

    $this->actingAs($user);

    expect(($this->props)())->toHaveKey('activity')
        ->and(($this->props)()['activity'])->toBeNull();
});
