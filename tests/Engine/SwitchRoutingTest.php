<?php

namespace Goldnead\StatamicAutomations\Tests\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * SwitchNode::execute() already resolves the matching case's output
 * handle, or "default" when nothing matches. These tests exercise that
 * behavior end-to-end through WorkflowRunner::nextNode() (arbitrary
 * from_output routing, established in Task 1.1) to prove a switch with
 * an unmatched value actually walks the graph via its wired "default"
 * edge — and that a matched case takes ONLY its own edge, not the
 * others.
 */
class SwitchRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_falls_through_to_default_when_nothing_matches(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'sw',
                'type' => 'switch',
                'config' => [
                    'value' => 'zzz',
                    'cases' => ['a' => 'a', 'b' => 'b'],
                ],
            ],
            ['key' => 'case_a', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'a ran']],
            ['key' => 'case_b', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'b ran']],
            ['key' => 'default_node', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'default ran']],
        ], [
            ['t', 'sw'],
            ['sw', 'case_a', 'a'],
            ['sw', 'case_b', 'b'],
            ['sw', 'default_node', 'default'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();

        $this->assertCount(0, $nodeRuns->where('node_key', 'case_a'), 'Unmatched case "a" must not run.');
        $this->assertCount(0, $nodeRuns->where('node_key', 'case_b'), 'Unmatched case "b" must not run.');
        $this->assertCount(1, $nodeRuns->where('node_key', 'default_node'), 'The default-wired node must run exactly once.');
    }

    public function test_switch_routes_to_the_matching_case_and_no_other_output(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'sw',
                'type' => 'switch',
                'config' => [
                    'value' => 'b',
                    'cases' => ['a' => 'a', 'b' => 'b'],
                ],
            ],
            ['key' => 'case_a', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'a ran']],
            ['key' => 'case_b', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'b ran']],
            ['key' => 'default_node', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'default ran']],
        ], [
            ['t', 'sw'],
            ['sw', 'case_a', 'a'],
            ['sw', 'case_b', 'b'],
            ['sw', 'default_node', 'default'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();

        $this->assertCount(1, $nodeRuns->where('node_key', 'case_b'), 'The matching case "b" must run exactly once.');
        $this->assertCount(0, $nodeRuns->where('node_key', 'case_a'), 'Case "a" must not run.');
        $this->assertCount(0, $nodeRuns->where('node_key', 'default_node'), 'default must not run when a case matched.');
    }

    /**
     * @param  array<int, array{key: string, type: string, config?: array<string, mixed>}>  $nodes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     */
    protected function buildAutomation(array $nodes, array $edges): Automation
    {
        $automation = Automation::create(['name' => 'T', 'handle' => 'test-'.uniqid()]);

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
