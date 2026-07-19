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
 * The Loop node's default "inline" mode iterates the downstream subgraph
 * wired to its "loop" output once per item, then continues via "done" —
 * instead of requiring a separate target automation.
 */
class LoopInlineTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_inline_loop_runs_body_once_per_item_then_done_once(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'loop',
                'type' => 'loop',
                'config' => [
                    'items' => ['a', 'b', 'c'],
                    'mode' => 'inline',
                ],
            ],
            [
                'key' => 'body',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => '{{item}}'],
            ],
            [
                'key' => 'done_log',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => 'done'],
            ],
        ], [
            ['t', 'loop'],
            ['loop', 'body', 'loop'],
            ['loop', 'done_log', 'done'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();

        $bodyRuns = $nodeRuns->where('node_key', 'body')->values();
        $doneRuns = $nodeRuns->where('node_key', 'done_log')->values();

        $this->assertCount(3, $bodyRuns, 'Loop body should run once per item.');
        $this->assertSame(
            ['a', 'b', 'c'],
            $bodyRuns->map(fn ($r) => $r->output['preview']['message'])->all(),
        );

        $this->assertCount(1, $doneRuns, 'Done branch should run exactly once, after the loop finishes.');
        $this->assertSame('done', $doneRuns->first()->output['preview']['message']);

        // "done" must come strictly after all three body runs.
        $lastBodyId = $bodyRuns->last()->id;
        $this->assertGreaterThan($lastBodyId, $doneRuns->first()->id);
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
