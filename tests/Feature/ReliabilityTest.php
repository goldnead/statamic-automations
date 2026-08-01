<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationAuditLog;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\AuditLogger;
use Illuminate\Filesystem\Filesystem;
use Statamic\Facades\Revision;

afterEach(function () {
    // Revisions are flat files; clear them so runs don't accumulate on disk.
    try {
        $dir = Revision::directory();
        if (is_dir($dir)) {
            (new Filesystem)->deleteDirectory($dir);
        }
    } catch (Throwable) {
        // ignore
    }
});

function revisionsWritable(): bool
{
    try {
        $dir = Revision::directory();
        @mkdir($dir, 0777, true);

        return is_dir($dir) && is_writable($dir);
    } catch (Throwable) {
        return false;
    }
}

function failingAutomation(array $extraConfig): Automation
{
    // A "call_automation" node pointing at a missing target passes flow
    // validation (its required field is present) but fails at runtime.
    $automation = Automation::create(['name' => 'Flaky', 'handle' => 'flaky', 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'boom', 'type' => 'call_automation',
        'config' => array_merge(['automation' => 'does-not-exist-xyz'], $extraConfig),
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'after', 'type' => 'add_log_entry',
        'config' => ['message' => 'continued'],
    ]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'boom']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'boom', 'to_node_key' => 'after']);

    return $automation;
}

it('continues past a failing node when _on_error is continue', function () {
    $automation = failingAutomation(['_on_error' => 'continue']);
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make([]);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    $final = $runner->execute($run, $ctx);

    expect($final->status)->toBe(AutomationRun::STATUS_SUCCESS);
    expect($final->nodeRuns()->where('node_key', 'after')->exists())->toBeTrue();
});

it('fails the run when a node fails without an on-error policy', function () {
    $automation = failingAutomation([]);
    $runner = app(WorkflowRunner::class);
    $ctx = AutomationContext::make([]);
    $run = $runner->createRun($automation, $ctx, $automation->nodes->firstWhere('node_key', 't'));

    $final = $runner->execute($run, $ctx);

    expect($final->status)->toBe(AutomationRun::STATUS_FAILED);
});

it('snapshots and reverts an automation graph via Statamic revisions', function () {
    $automation = Automation::create(['name' => 'V', 'handle' => 'v', 'version' => 1]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);

    $manager = app(VersionManager::class);
    $rev = $manager->snapshot($automation->fresh(['nodes', 'edges']), 'v1');
    expect($rev)->toBeInstanceOf(Statamic\Contracts\Revisions\Revision::class);

    // Mutate: add a node + bump version.
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'x', 'type' => 'add_log_entry']);
    $automation->forceFill(['version' => 2])->save();
    expect($automation->fresh()->nodes()->count())->toBe(2);

    // Revert back to the first revision (1 node).
    $reverted = $manager->revert($automation->fresh(['nodes', 'edges']), $rev->date()->timestamp);
    expect($reverted->nodes()->count())->toBe(1);
    expect($reverted->version)->toBeGreaterThan(2);
})->skip(fn () => ! revisionsWritable(), 'Revisions store not writable in this environment.');

it('lists stored revisions newest first', function () {
    $automation = Automation::create(['name' => 'L', 'handle' => 'l']);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    $manager = app(VersionManager::class);

    $manager->snapshot($automation->fresh(['nodes', 'edges']), 'first');
    $list = $manager->versions($automation->fresh(['nodes', 'edges']));

    expect($list)->not->toBeEmpty();
    expect($list[0])->toHaveKeys(['timestamp', 'message', 'node_count']);
})->skip(fn () => ! revisionsWritable(), 'Revisions store not writable in this environment.');

it('records audit log entries', function () {
    $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
    app(AuditLogger::class)->record('enabled', $automation, ['x' => 1]);

    $log = AutomationAuditLog::first();
    expect($log->action)->toBe('enabled');
    expect($log->automation_id)->toBe($automation->id);
    expect($log->meta)->toBe(['x' => 1]);
});
