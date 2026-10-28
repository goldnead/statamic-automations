<?php

use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Run storage must stay bounded: old runs go (with their node runs, explicitly,
 * not on the grounds of a foreign key that SQLite may not enforce), the prune is
 * on the scheduler, and one node run cannot store megabytes.
 */
beforeEach(function (): void {
    $this->automation = Automation::create(['name' => 'Series', 'handle' => 'series']);

    $this->makeRun = function (int $daysAgo, string $status = AutomationRun::STATUS_SUCCESS): AutomationRun {
        $run = AutomationRun::create([
            'automation_id' => $this->automation->id,
            'automation_uuid' => $this->automation->uuid,
            'status' => $status,
        ]);
        AutomationNodeRun::create([
            'automation_run_id' => $run->id,
            'node_key' => 'n',
            'node_type' => 'http_request',
            'status' => AutomationNodeRun::STATUS_SUCCESS,
            'input' => ['a' => 1],
        ]);
        DB::table('automation_runs')->where('id', $run->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return $run;
    };
});

it('prunes runs older than the retention window and their node runs with them', function (): void {
    expect(config('automations.runs.prune_after_days'))->toBe(30);
    config(['automations.runs.prune_after_days' => 7]);

    $old = ($this->makeRun)(10);
    $fresh = ($this->makeRun)(2);

    // SQLite does not enforce the cascade unless foreign keys are on; the
    // command must not depend on it.
    DB::statement('PRAGMA foreign_keys = OFF');

    $this->artisan('automations:prune')->assertSuccessful();

    expect(AutomationRun::whereKey($old->id)->exists())->toBeFalse()
        ->and(AutomationNodeRun::where('automation_run_id', $old->id)->exists())->toBeFalse()
        ->and(AutomationRun::whereKey($fresh->id)->exists())->toBeTrue()
        ->and(AutomationNodeRun::where('automation_run_id', $fresh->id)->exists())->toBeTrue();
});

it('schedules the prune daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'automations:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('15 3 * * *');
});

it('caps the stored payload of a node run and marks the cut', function (): void {
    config(['automations.runs.max_node_io_bytes' => 1024]);

    $big = ['blob' => str_repeat('x', 500_000)];

    $nodeRun = app(RunLogger::class)->recordNodeRun(
        ($this->makeRun)(0),
        'big',
        'http_request',
        $big,
        ActionResult::success($big),
    );

    $stored = DB::table('automation_node_runs')->where('id', $nodeRun->id)->first();

    expect(strlen((string) $stored->input))->toBeLessThan(4096)
        ->and(strlen((string) $stored->output))->toBeLessThan(4096);

    $fresh = $nodeRun->fresh();
    expect($fresh->input['_truncated'])->toBeTrue()
        ->and($fresh->input['_original_bytes'])->toBeGreaterThan(500_000)
        ->and($fresh->output['_truncated'])->toBeTrue();
});

it('leaves a payload under the cap untouched', function (): void {
    $small = ['a' => 1, 'b' => ['c' => 'd']];

    $nodeRun = app(RunLogger::class)->recordNodeRun(
        ($this->makeRun)(0),
        'small',
        'http_request',
        $small,
        ActionResult::success($small),
    );

    expect($nodeRun->fresh()->input)->toBe($small)
        ->and($nodeRun->fresh()->output)->toBe($small);
});
