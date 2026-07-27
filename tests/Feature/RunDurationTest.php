<?php

/**
 * Regression tests for run and node-run timing.
 *
 * Three defects are pinned here:
 *
 *  1. A run resumed after a Delay had `started_at` overwritten by the resume
 *     job, so the original start (and with it the whole wait) was lost and
 *     `started_at == finished_at`.
 *  2. `duration_ms` was computed as `$finished->diffInMilliseconds($started)`,
 *     which is NEGATIVE under Carbon 3's signed diff — the CP showed
 *     "DURATION -652 ms".
 *  3. Node runs stamped `started_at` and `finished_at` from two `now()` calls
 *     taken *after* the node had already executed, so every node reported
 *     0 ms regardless of how long it actually took.
 */

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * trigger(manual) → slow action → delay(1 day) → action
 */
function durationAutomation(): Automation
{
    $automation = Automation::create([
        'name' => 'Timed', 'handle' => 'timed', 'enabled' => true,
    ]);

    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'slow', 'type' => 'timing.slow',
        'config' => ['ms' => 250],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'wait', 'type' => 'delay',
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'after', 'type' => 'add_log_entry',
        'config' => ['message' => 'done'],
    ]);

    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'slow']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'slow', 'to_node_key' => 'wait']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'wait', 'to_node_key' => 'after']);

    return $automation;
}

function startDurationRun(Automation $automation): AutomationRun
{
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make([]);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    return $runner->execute($run, $ctx);
}

it('keeps the original started_at when a run resumes after a delay', function () {
    Automations::registerAction(SlowTestAction::class);
    Carbon::setTestNow('2026-07-27 09:00:00');

    $automation = durationAutomation();
    $run = startDurationRun($automation);

    expect($run->status)->toBe(AutomationRun::STATUS_WAITING);
    $originalStart = $run->started_at->copy();

    // The delay elapses; the scheduler resumes the run a day and a bit later.
    Carbon::setTestNow('2026-07-28 09:30:00');
    $this->artisan('automations:run-due')->assertExitCode(0);

    $fresh = $run->fresh();

    expect($fresh->status)->toBe(AutomationRun::STATUS_SUCCESS);
    // The resume must NOT restamp the origin of the run.
    expect($fresh->started_at->equalTo($originalStart))->toBeTrue();
    expect($fresh->finished_at->greaterThan($fresh->started_at))->toBeTrue();
});

it('reports a positive wall-clock duration spanning the delay', function () {
    Automations::registerAction(SlowTestAction::class);
    Carbon::setTestNow('2026-07-27 09:00:00');

    $automation = durationAutomation();
    $run = startDurationRun($automation);

    // Exactly 24h30m later.
    Carbon::setTestNow('2026-07-28 09:30:00');
    $this->artisan('automations:run-due')->assertExitCode(0);

    $fresh = $run->fresh();

    // The headline guard: never negative. This is what the CP rendered as
    // "DURATION -652 ms".
    expect($fresh->duration_ms)->toBeGreaterThan(0);

    // duration_ms is wall clock: start → finish, wait included — exactly the
    // 24h30m between the two clock positions above.
    expect($fresh->duration_ms)->toBe(((24 * 3600) + (30 * 60)) * 1000);
});

it('measures how long a node actually took', function () {
    Automations::registerAction(SlowTestAction::class);
    Carbon::setTestNow('2026-07-27 09:00:00');

    $automation = durationAutomation();
    $run = startDurationRun($automation);

    $slow = $run->nodeRuns()->where('node_key', 'slow')->first();

    expect($slow)->not->toBeNull();
    // Before the fix both timestamps were read after execution — always 0.
    expect($slow->duration_ms)->toBe(250);

    // The neighbouring nodes did no work and legitimately round to 0 — the
    // point is that 250 and 0 are now told apart at all.
    expect($run->nodeRuns()->where('node_key', 't')->first()->duration_ms)->toBe(0);
});

/*
 * Note: `started_at`/`finished_at` are plain `timestamp` columns (whole-second
 * precision), so a sub-second node collapses to a single stored second. The
 * millisecond truth lives in `duration_ms`, which is computed in PHP before
 * persisting — that is the value the CP renders.
 */

it('never stores a negative node duration when the clock steps backwards', function () {
    Automations::registerAction(BackwardsClockAction::class);
    Carbon::setTestNow('2026-07-27 09:00:00');

    $automation = Automation::create([
        'name' => 'Skew', 'handle' => 'skew', 'enabled' => true,
    ]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'back', 'type' => 'timing.backwards',
    ]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'back']);

    $run = startDurationRun($automation);

    expect($run->nodeRuns()->where('node_key', 'back')->first()->duration_ms)->toBe(0);
    expect($run->duration_ms)->toBeGreaterThanOrEqual(0);
});

/**
 * An action that consumes a configurable, deterministic amount of wall clock
 * by advancing Carbon's test clock — the only way to assert a real node
 * duration without sleeping in the test suite.
 */
class SlowTestAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'timing.slow';
    }

    public static function label(): string
    {
        return 'Slow (test)';
    }

    public static function description(): ?string
    {
        return 'Burns a fixed number of milliseconds.';
    }

    public static function group(): string
    {
        return 'Testing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return ['slept_ms' => 'integer'];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $ms = (int) ($config['ms'] ?? 100);
        Carbon::setTestNow(Carbon::now()->addMilliseconds($ms));

        return ActionResult::success(['slept_ms' => $ms]);
    }
}

/**
 * Simulates an NTP step backwards during a node's execution.
 */
class BackwardsClockAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'timing.backwards';
    }

    public static function label(): string
    {
        return 'Backwards clock (test)';
    }

    public static function description(): ?string
    {
        return 'Steps the clock backwards mid-execution.';
    }

    public static function group(): string
    {
        return 'Testing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        Carbon::setTestNow(Carbon::now()->subSeconds(5));

        return ActionResult::success([]);
    }
}
