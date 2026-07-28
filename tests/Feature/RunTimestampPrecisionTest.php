<?php

use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two events inside the same second must remain distinguishable.
 *
 * Before this release `started_at` / `finished_at` were whole-second
 * `timestamp` columns *and* Eloquent serialised them with `Y-m-d H:i:s`, so a
 * node that ran in 40 ms shared a stored instant with the node before it. Only
 * `duration_ms` knew the difference — enough to render a duration, not enough
 * to order or correlate by point in time.
 */
function precisionRun(array $attributes = []): AutomationRun
{
    return AutomationRun::create(array_merge([
        'status' => AutomationRun::STATUS_RUNNING,
    ], $attributes));
}

it('keeps two run stamps inside the same second apart', function () {
    $start = Carbon::parse('2026-07-28 12:00:00.125');
    $finish = Carbon::parse('2026-07-28 12:00:00.874');

    $run = precisionRun(['started_at' => $start, 'finished_at' => $finish]);

    $reloaded = AutomationRun::findOrFail($run->id);

    expect($reloaded->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:00.125');
    expect($reloaded->finished_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:00.874');
    expect($reloaded->started_at->equalTo($reloaded->finished_at))->toBeFalse();

    // The millisecond is really on disk, not reconstructed in PHP.
    expect(DB::table('automation_runs')->where('id', $run->id)->value('started_at'))
        ->toContain('12:00:00.125');
});

it('keeps two node-run stamps inside the same second apart', function () {
    $run = precisionRun();

    $first = AutomationNodeRun::create([
        'automation_run_id' => $run->id,
        'node_key' => 'a', 'node_type' => 'log', 'status' => 'success',
        'started_at' => Carbon::parse('2026-07-28 12:00:00.010'),
        'finished_at' => Carbon::parse('2026-07-28 12:00:00.050'),
    ]);

    $second = AutomationNodeRun::create([
        'automation_run_id' => $run->id,
        'node_key' => 'b', 'node_type' => 'log', 'status' => 'success',
        'started_at' => Carbon::parse('2026-07-28 12:00:00.051'),
        'finished_at' => Carbon::parse('2026-07-28 12:00:00.090'),
    ]);

    $a = AutomationNodeRun::findOrFail($first->id);
    $b = AutomationNodeRun::findOrFail($second->id);

    expect($a->started_at->format('v'))->toBe('010');
    expect($b->started_at->format('v'))->toBe('051');
    expect($a->finished_at->lessThan($b->started_at))->toBeTrue();
});

it('orders sub-second node runs by stored time, not by insertion order', function () {
    $run = precisionRun();

    // Insert out of chronological order so an ordering that falls back to `id`
    // would produce the wrong answer.
    foreach (['0.400' => 'late', '0.100' => 'early'] as $fraction => $key) {
        AutomationNodeRun::create([
            'automation_run_id' => $run->id,
            'node_key' => $key, 'node_type' => 'log', 'status' => 'success',
            'started_at' => Carbon::parse('2026-07-28 12:00:0' . $fraction),
            'finished_at' => Carbon::parse('2026-07-28 12:00:0' . $fraction),
        ]);
    }

    $keys = AutomationNodeRun::where('automation_run_id', $run->id)
        ->orderBy('started_at')
        ->pluck('node_key')
        ->all();

    expect($keys)->toBe(['early', 'late']);
});

it('records sub-second node runs through RunLogger without collapsing them', function () {
    $run = precisionRun(['started_at' => now()]);
    $logger = new RunLogger(app(TokenResolver::class));

    $startA = Carbon::parse('2026-07-28 12:00:00.200');
    $startB = Carbon::parse('2026-07-28 12:00:00.600');

    Carbon::setTestNow('2026-07-28 12:00:00.400');
    $a = $logger->recordNodeRun($run, 'a', 'log', [], ActionResult::success(), null, $startA);

    Carbon::setTestNow('2026-07-28 12:00:00.900');
    $b = $logger->recordNodeRun($run, 'b', 'log', [], ActionResult::success(), null, $startB);

    Carbon::setTestNow();

    $a = AutomationNodeRun::findOrFail($a->id);
    $b = AutomationNodeRun::findOrFail($b->id);

    expect($a->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:00.200');
    expect($a->finished_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:00.400');
    expect($b->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:00.600');

    // Both nodes ran inside one second, and both are still individually placed.
    expect($a->started_at->format('Y-m-d H:i:s'))->toBe($b->started_at->format('Y-m-d H:i:s'));
    expect($a->duration_ms)->toBe(200);
    expect($b->duration_ms)->toBe(300);
});

it('still reads a whole-second stamp written before this release', function () {
    $run = precisionRun();

    DB::table('automation_runs')->where('id', $run->id)->update([
        'started_at' => '2026-07-28 12:00:00',
        'finished_at' => '2026-07-28 12:00:01',
    ]);

    $reloaded = AutomationRun::findOrFail($run->id);

    expect($reloaded->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:00.000');
    expect($reloaded->finished_at->format('Y-m-d H:i:s.v'))->toBe('2026-07-28 12:00:01.000');
});

it('declares timestamp(3) on drivers that have typed datetimes', function () {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        // SQLite has no typed datetime: `timestamp` and `timestamp(3)` both map
        // to a text `datetime` column, so the migration deliberately skips the
        // rebuild here. Precision on SQLite comes from the written string —
        // which the tests above prove is present.
        expect(collect(Schema::getColumns('automation_runs'))->firstWhere('name', 'started_at')['type'])
            ->toBe('datetime');

        return;
    }

    foreach (['automation_runs', 'automation_node_runs'] as $table) {
        foreach (['started_at', 'finished_at'] as $column) {
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type'];

            expect($type)->toContain('(3)');
        }
    }
});
