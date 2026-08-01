<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class WorkflowRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_linear_flow_with_email_in_test_mode(): void
    {
        Mail::fake();

        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'email',
                'type' => 'send_email',
                'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Hi {{ form.email }}',
                    'body' => 'Hello!',
                ],
            ],
        ], [['t', 'email']]);

        $context = AutomationContext::make(['form' => ['email' => 'jane@example.com']], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->first());
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);
        Mail::assertNothingSent();
    }

    public function test_filter_stops_flow_when_conditions_fail(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'f',
                'type' => 'filter',
                'config' => [
                    'mode' => 'all',
                    'conditions' => [
                        ['field' => 'lead.status', 'operator' => 'equals', 'value' => 'Qualified'],
                    ],
                ],
            ],
            [
                'key' => 'log',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => 'should not run'],
            ],
        ], [['t', 'f'], ['f', 'log']]);

        $context = AutomationContext::make(['lead' => ['status' => 'New']], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->first());
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_STOPPED, $finalRun->status);
    }

    public function test_branch_takes_true_path_when_condition_matches(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'b',
                'type' => 'branch',
                'config' => [
                    'mode' => 'all',
                    'conditions' => [
                        ['field' => 'lead.status', 'operator' => 'equals', 'value' => 'Qualified'],
                    ],
                ],
            ],
            ['key' => 'true_path', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'qualified']],
            ['key' => 'false_path', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'not qualified']],
        ], [
            ['t', 'b'],
            ['b', 'true_path', 'true'],
            ['b', 'false_path', 'false'],
        ]);

        $context = AutomationContext::make(['lead' => ['status' => 'Qualified']], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->first());
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeKeys = $finalRun->nodeRuns()->pluck('node_key')->all();
        $this->assertContains('true_path', $nodeKeys);
        $this->assertNotContains('false_path', $nodeKeys);
    }

    public function test_stop_node_ends_run_with_stopped_status(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 's', 'type' => 'stop'],
        ], [['t', 's']]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->first());
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_STOPPED, $finalRun->status);
    }

    public function test_persists_node_runs(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'hi']],
        ], [['t', 'log']]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->first());
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(2, $finalRun->nodeRuns()->count());
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
