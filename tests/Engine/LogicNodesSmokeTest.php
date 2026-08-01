<?php

namespace Goldnead\StatamicAutomations\Tests\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Task 1.4: end-to-end smoke coverage for the five remaining logic/action
 * nodes, proving each does what its description claims when driven through
 * the real WorkflowRunner (not just called in isolation, as the existing
 * unit tests in tests/Unit/NewNodesTest.php and
 * tests/Feature/ControlFlowNodesTest.php already do).
 */
class LogicNodesSmokeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    // -- Delay ---------------------------------------------------------

    public function test_delay_pauses_the_run_and_schedules_a_resume(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'd', 'type' => 'delay', 'config' => ['amount' => 10, 'unit' => 'minutes']],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['message' => 'after delay']],
        ], [
            ['t', 'd'],
            ['d', 'after'],
        ]);

        $run = $this->runAutomation($automation);

        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $this->assertNull($run->finished_at);

        $job = AutomationScheduledJob::where('automation_run_id', $run->id)->first();
        $this->assertNotNull($job, 'Delay must schedule a resume job.');
        $this->assertSame('d', $job->node_key);
        $this->assertSame(AutomationScheduledJob::STATUS_PENDING, $job->status);
        $this->assertTrue($job->due_at->diffInSeconds(now()->addMinutes(10)) < 2);

        $this->assertFalse($run->nodeRuns()->where('node_key', 'after')->exists());
    }

    // -- WaitUntil -------------------------------------------------------

    public function test_wait_until_pauses_while_unmet_and_proceeds_once_the_condition_is_met(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'w', 'type' => 'wait_until',
                'config' => [
                    'mode' => 'all',
                    'conditions' => [['field' => 'lead.status', 'operator' => 'equals', 'value' => 'qualified']],
                    'recheck_minutes' => 5,
                ],
            ],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['message' => 'lead is qualified']],
        ], [
            ['t', 'w'],
            ['w', 'after'],
        ]);

        $run = $this->runAutomation($automation, ['lead' => ['status' => 'new']]);

        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $this->assertFalse($run->nodeRuns()->where('node_key', 'after')->exists());

        $job = AutomationScheduledJob::where('automation_run_id', $run->id)->first();
        $this->assertNotNull($job);
        $this->assertSame('w', $job->node_key);

        // First recheck: condition is STILL unmet. The node must be
        // re-evaluated (not blindly skipped) and re-park the run for
        // another interval, instead of falling through to "after".
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status, 'A still-unmet condition must keep the run parked.');
        $this->assertFalse($run->nodeRuns()->where('node_key', 'after')->exists());

        $rechecked = AutomationScheduledJob::where('automation_run_id', $run->id)
            ->where('status', AutomationScheduledJob::STATUS_PENDING)
            ->first();
        $this->assertNotNull($rechecked, 'A still-unmet wait_until must schedule another recheck.');

        // Condition becomes true before the next recheck fires — mutate the
        // persisted pause-time context the resumer will rebuild from.
        $payload = $rechecked->payload;
        $payload['context']['lead']['status'] = 'qualified';
        $rechecked->forceFill(['payload' => $payload])->save();

        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->nodeRuns()->where('node_key', 'after')->exists());
    }

    public function test_wait_until_proceeds_immediately_when_already_met(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'w', 'type' => 'wait_until',
                'config' => [
                    'mode' => 'all',
                    'conditions' => [['field' => 'lead.status', 'operator' => 'equals', 'value' => 'qualified']],
                ],
            ],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['message' => 'lead is qualified']],
        ], [
            ['t', 'w'],
            ['w', 'after'],
        ]);

        $run = $this->runAutomation($automation, ['lead' => ['status' => 'qualified']]);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->nodeRuns()->where('node_key', 'after')->exists());
        $this->assertFalse(AutomationScheduledJob::where('automation_run_id', $run->id)->exists());
    }

    // -- Throttle ----------------------------------------------------------

    public function test_throttle_stops_a_duplicate_run_within_the_window_but_allows_a_different_key(): void
    {
        Cache::flush();

        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'th', 'type' => 'throttle', 'config' => ['key' => '{{ order.id }}', 'window_minutes' => 60]],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['message' => 'order processed']],
        ], [
            ['t', 'th'],
            ['th', 'after'],
        ]);

        $first = $this->runAutomation($automation, ['order' => ['id' => 'order-7']]);
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $first->status);
        $this->assertTrue($first->nodeRuns()->where('node_key', 'after')->exists());

        $second = $this->runAutomation($automation, ['order' => ['id' => 'order-7']]);
        $this->assertSame(AutomationRun::STATUS_STOPPED, $second->status);
        $this->assertFalse($second->nodeRuns()->where('node_key', 'after')->exists());

        $third = $this->runAutomation($automation, ['order' => ['id' => 'order-8']]);
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $third->status);
        $this->assertTrue($third->nodeRuns()->where('node_key', 'after')->exists());
    }

    // -- SetVariable ---------------------------------------------------

    public function test_set_variable_writes_a_var_readable_by_a_downstream_log_entry(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'sv', 'type' => 'set_variable',
                'config' => ['variables' => ['greeting' => 'hello {{ lead.name }}']],
            ],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['message' => '{{ vars.greeting }}']],
        ], [
            ['t', 'sv'],
            ['sv', 'after'],
        ]);

        $run = $this->runAutomation($automation, ['lead' => ['name' => 'Anna']]);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $nodeRun = $run->nodeRuns()->where('node_key', 'after')->first();
        $this->assertNotNull($nodeRun);
        $this->assertSame('hello Anna', $nodeRun->output['message'] ?? null);
    }

    // -- CallAutomation --------------------------------------------------

    public function test_call_automation_runs_the_target_and_waits_before_continuing(): void
    {
        $child = Automation::create(['name' => 'Child', 'handle' => 'smoke-child', 'enabled' => true]);
        AutomationNode::create(['automation_id' => $child->id, 'node_key' => 't', 'type' => 'manual']);
        AutomationNode::create([
            'automation_id' => $child->id, 'node_key' => 'log', 'type' => 'add_log_entry',
            'config' => ['message' => 'child ran'],
        ]);
        AutomationEdge::create(['automation_id' => $child->id, 'from_node_key' => 't', 'to_node_key' => 'log']);

        $parent = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'call', 'type' => 'call_automation', 'config' => ['automation' => 'smoke-child', 'wait' => true]],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['message' => 'parent continued']],
        ], [
            ['t', 'call'],
            ['call', 'after'],
        ]);

        $run = $this->runAutomation($parent);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->nodeRuns()->where('node_key', 'after')->exists(), 'Parent must continue after waiting for the child.');

        $childRun = AutomationRun::where('automation_id', $child->id)->first();
        $this->assertNotNull($childRun, 'call_automation must actually run the target automation.');
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $childRun->status);
        $this->assertTrue($childRun->nodeRuns()->where('node_key', 'log')->exists());
    }

    // -- helpers -----------------------------------------------------------

    protected function runAutomation(Automation $automation, array $seed = []): AutomationRun
    {
        $automation = $automation->fresh()->load(['nodes', 'edges']);
        $context = AutomationContext::make($seed);
        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));

        return $runner->execute($run, $context);
    }

    /**
     * @param  array<int, array{key: string, type: string, config?: array<string, mixed>}>  $nodes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     */
    protected function buildAutomation(array $nodes, array $edges): Automation
    {
        $automation = Automation::create(['name' => 'Smoke', 'handle' => 'smoke-'.uniqid(), 'enabled' => true]);

        foreach ($nodes as $node) {
            AutomationNode::create([
                'automation_id' => $automation->id,
                'node_key' => $node['key'],
                'type' => $node['type'],
                'config' => $node['config'] ?? [],
            ]);
        }

        foreach ($edges as $edge) {
            AutomationEdge::create([
                'automation_id' => $automation->id,
                'from_node_key' => $edge[0],
                'from_output' => $edge[2] ?? 'default',
                'to_node_key' => $edge[1],
            ]);
        }

        return $automation->fresh()->load(['nodes', 'edges']);
    }
}
