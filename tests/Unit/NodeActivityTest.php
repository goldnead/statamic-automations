<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActivityWindow;
use Goldnead\StatamicAutomations\Support\NodeActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where people are inside an automation, counted out of the node runs the
 * engine already writes.
 */
beforeEach(function (): void {
    $this->activity = app(NodeActivity::class);

    $this->automation = Automation::create(['name' => 'Series', 'handle' => 'series']);

    $this->run = function (array $attributes = []): AutomationRun {
        return AutomationRun::create(array_merge([
            'automation_id' => $this->automation->id,
            'automation_uuid' => $this->automation->uuid,
            'status' => AutomationRun::STATUS_SUCCESS,
        ], $attributes));
    };

    $this->step = function (AutomationRun $run, string $nodeKey, string $status = AutomationNodeRun::STATUS_SUCCESS): AutomationNodeRun {
        return AutomationNodeRun::create([
            'automation_run_id' => $run->id,
            'automation_uuid' => $run->automation_uuid,
            'is_test' => (bool) $run->is_test,
            'node_key' => $nodeKey,
            'node_type' => 'send_email',
            'status' => $status,
        ]);
    };
});

it('counts what reached each step, what got through it and what broke on it', function (): void {
    $first = ($this->run)();
    ($this->step)($first, 'welcome');
    ($this->step)($first, 'reminder');

    $second = ($this->run)();
    ($this->step)($second, 'welcome');
    ($this->step)($second, 'reminder', AutomationNodeRun::STATUS_FAILED);

    $third = ($this->run)();
    ($this->step)($third, 'welcome');
    ($this->step)($third, 'reminder', AutomationNodeRun::STATUS_SKIPPED);

    $stats = $this->activity->forAutomation($this->automation->uuid);

    expect($stats['welcome'])->toBe(['reached' => 3, 'completed' => 3, 'failed' => 0])
        // Three arrived, one got through, one broke, one was skipped. The
        // skipped row is the difference between reached and the two named
        // outcomes, which is exactly "did not get through here".
        ->and($stats['reminder'])->toBe(['reached' => 3, 'completed' => 1, 'failed' => 1])
        // Keyed by node, in whatever order the database grouped them: the order
        // of a funnel belongs to the graph, and only the caller has that.
        ->and(array_keys($stats))->toHaveCount(2);
});

it('says nothing at all about a step nothing has run through', function (): void {
    // The canvas draws what it is given, and a fresh automation whose every card
    // reads "0 / 0 / 0" looks broken rather than new. Absent is the answer, not
    // a row of zeroes.
    $run = ($this->run)();
    ($this->step)($run, 'welcome');

    $stats = $this->activity->forAutomation($this->automation->uuid);

    expect($stats)->toHaveKey('welcome')
        ->and($stats)->not->toHaveKey('reminder');
});

it('returns an empty map for an automation nobody has been through', function (): void {
    expect($this->activity->forAutomation($this->automation->uuid))->toBe([]);
});

it('leaves test runs out, the same way the funnel does', function (): void {
    ($this->step)(($this->run)(), 'welcome');
    ($this->step)(($this->run)(['is_test' => true]), 'welcome');

    expect($this->activity->forAutomation($this->automation->uuid)['welcome']['reached'])->toBe(1)
        ->and($this->activity->forAutomation($this->automation->uuid, null, includeTests: true)['welcome']['reached'])->toBe(2);
});

it('keeps a step whose node has been deleted from the graph', function (): void {
    // A node run outlives the node. The graph is edited; the log is not, and a
    // funnel that quietly loses a step it no longer recognises is the version of
    // this screen that lies about where people went.
    ($this->step)(($this->run)(), 'a-step-that-was-removed');

    expect($this->activity->forAutomation($this->automation->uuid))
        ->toHaveKey('a-step-that-was-removed');
});

it('excludes what happened outside the window', function (): void {
    $old = $this->travelTo(now()->subDays(45), function () {
        $run = ($this->run)();
        ($this->step)($run, 'welcome');

        return $run;
    });

    ($this->step)(($this->run)(), 'welcome');

    expect($old)->toBeInstanceOf(AutomationRun::class)
        ->and($this->activity->forAutomation($this->automation->uuid, ActivityWindow::make('30'))['welcome']['reached'])->toBe(1)
        ->and($this->activity->forAutomation($this->automation->uuid, ActivityWindow::make('90'))['welcome']['reached'])->toBe(2)
        ->and($this->activity->forAutomation($this->automation->uuid, ActivityWindow::make('all'))['welcome']['reached'])->toBe(2);
});

it('asks one query however many rows there are', function (): void {
    // Measured, not assumed: the whole reason `automation_uuid` and `is_test`
    // were copied onto the node run is that this must not fan out per run.
    foreach (range(1, 3) as $ignored) {
        $run = ($this->run)();
        ($this->step)($run, 'welcome');
        ($this->step)($run, 'reminder');
    }

    $counted = 0;
    DB::listen(function () use (&$counted): void {
        $counted++;
    });

    $this->activity->forAutomation($this->automation->uuid, ActivityWindow::make('30'));

    expect($counted)->toBe(1);

    foreach (range(1, 20) as $ignored) {
        $run = ($this->run)();
        ($this->step)($run, 'welcome');
        ($this->step)($run, 'reminder');
    }

    $counted = 0;
    $this->activity->forAutomation($this->automation->uuid, ActivityWindow::make('30'));

    expect($counted)->toBe(1);
});

it('has the composite index the per-node counts need', function (): void {
    // Without it, "this automation's node runs in this window" is a scan of
    // every node run in the install — the table that grows fastest of all.
    $indexes = collect(Schema::getIndexes('automation_node_runs'))->pluck('columns');

    expect($indexes)->toContain(['automation_uuid', 'node_key', 'created_at']);
});

it('takes the automation and the test flag from the run when nobody supplies them', function (): void {
    // The engine hands both over, because it has the run in hand. Everything
    // else — a future writer, a fixture — must still land with a correct
    // automation instead of a row invisible to every activity query.
    $run = ($this->run)(['is_test' => true]);

    $nodeRun = AutomationNodeRun::create([
        'automation_run_id' => $run->id,
        'node_key' => 'welcome',
        'node_type' => 'send_email',
        'status' => AutomationNodeRun::STATUS_SUCCESS,
    ]);

    expect($nodeRun->automation_uuid)->toBe($this->automation->uuid)
        ->and($nodeRun->is_test)->toBeTrue();
});

it('counts one person once, however many times a loop sent them through a step', function (): void {
    // `driveInlineLoop()` calls `runFrom()` once per iteration, and every pass
    // writes a row for every body node; a wait-until node is written again
    // when the run resumes. Counting rows made a loop over ten items report
    // its body node ten times over — and because the bars are drawn against
    // the busiest node, every other step then shrank to a fraction of it. The
    // view drew a collapse exactly where none had happened.
    $looper = ($this->run)();

    ($this->step)($looper, 'start');

    foreach (range(1, 10) as $pass) {
        ($this->step)($looper, 'loop_body');
    }

    $other = ($this->run)();
    ($this->step)($other, 'start');

    $totals = $this->activity->forAutomation($this->automation->uuid);

    expect($totals['start']['reached'])->toBe(2)
        ->and($totals['loop_body']['reached'])->toBe(1)
        ->and($totals['loop_body']['completed'])->toBe(1);
});

it('counts a retried step as one arrival, not as one failure plus one success', function (): void {
    $run = ($this->run)();

    ($this->step)($run, 'flaky', AutomationNodeRun::STATUS_FAILED);
    ($this->step)($run, 'flaky', AutomationNodeRun::STATUS_SUCCESS);

    $totals = $this->activity->forAutomation($this->automation->uuid);

    // Reached once. That the same run shows up under both outcomes is honest —
    // it did fail there, and it did get through — but it must not arrive twice.
    expect($totals['flaky']['reached'])->toBe(1)
        ->and($totals['flaky']['completed'])->toBe(1)
        ->and($totals['flaky']['failed'])->toBe(1);
});
