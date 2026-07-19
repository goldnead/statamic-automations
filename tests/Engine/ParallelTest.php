<?php

namespace Goldnead\StatamicAutomations\Tests\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * The Parallel node's "inline" mode (opt-in via `mode: inline`, distinct
 * from the pre-existing "automation" scatter/gather-via-sub-run legacy
 * mode which stays the default so existing wiring keeps working) fans
 * out to every subgraph wired to its declared branch outputs — running
 * ALL of them, not just the first connected edge.
 */
class ParallelTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_inline_parallel_fans_out_to_every_connected_branch(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'par',
                'type' => 'parallel',
                'config' => [
                    'mode' => 'inline',
                    'branches' => [
                        'branch_1' => 'First',
                        'branch_2' => 'Second',
                        'branch_3' => 'Third',
                    ],
                ],
            ],
            ['key' => 'log_1', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'one']],
            ['key' => 'log_2', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'two']],
            ['key' => 'log_3', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'three']],
        ], [
            ['t', 'par'],
            ['par', 'log_1', 'branch_1'],
            ['par', 'log_2', 'branch_2'],
            ['par', 'log_3', 'branch_3'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();

        $this->assertCount(1, $nodeRuns->where('node_key', 'log_1'), 'Branch 1 must run.');
        $this->assertCount(1, $nodeRuns->where('node_key', 'log_2'), 'Branch 2 must run.');
        $this->assertCount(1, $nodeRuns->where('node_key', 'log_3'), 'Branch 3 must run.');
    }

    /**
     * A branch output with no wired edge is simply skipped — fan-out
     * still runs every branch that IS connected.
     */
    public function test_inline_parallel_skips_unwired_branches_but_runs_the_rest(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'par',
                'type' => 'parallel',
                'config' => [
                    'mode' => 'inline',
                    'branches' => [
                        'branch_1' => 'First',
                        'branch_2' => 'Second',
                    ],
                ],
            ],
            ['key' => 'log_1', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'one']],
        ], [
            ['t', 'par'],
            ['par', 'log_1', 'branch_1'],
            // branch_2 intentionally left unwired.
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();
        $this->assertCount(1, $nodeRuns->where('node_key', 'log_1'));
    }

    /**
     * @param  array<int, array{key: string, type: string, config?: array<string, mixed>}>  $nodes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     */
    protected function buildAutomation(array $nodes, array $edges): Automation
    {
        $automation = Automation::create(['name' => 'T', 'handle' => 'test-' . uniqid()]);

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
