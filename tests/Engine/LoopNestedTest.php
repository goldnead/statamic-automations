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
 * Two inline loops nested inside each other, both using the SAME item
 * variable name ("item", the default). The inner loop must shadow the
 * outer loop's `item` while it runs, and the outer loop must see its own
 * `item` restored again once the inner loop's "done" output is reached.
 */
class LoopNestedTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_inner_loop_shadows_outer_loop_variables_and_restores_on_exit(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'outer',
                'type' => 'loop',
                'config' => ['items' => ['x', 'y'], 'mode' => 'inline'],
            ],
            [
                'key' => 'inner',
                'type' => 'loop',
                'config' => ['items' => ['1', '2'], 'mode' => 'inline'],
            ],
            [
                'key' => 'inner_log',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => '{{item}}'],
            ],
            [
                'key' => 'after_inner_log',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => 'outer:{{item}}'],
            ],
            [
                'key' => 'outer_done',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => 'done'],
            ],
        ], [
            ['t', 'outer'],
            ['outer', 'inner', 'loop'],
            ['outer', 'outer_done', 'done'],
            ['inner', 'inner_log', 'loop'],
            ['inner', 'after_inner_log', 'done'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();

        $innerLogRuns = $nodeRuns->where('node_key', 'inner_log')->values();
        $afterInnerRuns = $nodeRuns->where('node_key', 'after_inner_log')->values();
        $outerDoneRuns = $nodeRuns->where('node_key', 'outer_done')->values();

        // Inner loop runs fully (2 items) for each outer item (2 items) = 4.
        $this->assertCount(4, $innerLogRuns);
        $this->assertSame(
            ['1', '2', '1', '2'],
            $innerLogRuns->map(fn ($r) => $r->output['preview']['message'])->all(),
        );

        // After the inner loop finishes, the outer loop's `item` must be
        // restored (not left as the inner loop's last value) — proves
        // shadow + restore across the nested scope stack.
        $this->assertCount(2, $afterInnerRuns);
        $this->assertSame(
            ['outer:x', 'outer:y'],
            $afterInnerRuns->map(fn ($r) => $r->output['preview']['message'])->all(),
        );

        // Outer "done" runs exactly once, after both outer iterations.
        $this->assertCount(1, $outerDoneRuns);
        $this->assertSame('done', $outerDoneRuns->first()->output['preview']['message']);
        $this->assertGreaterThan($afterInnerRuns->last()->id, $outerDoneRuns->first()->id);
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
