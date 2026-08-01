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

class PartialRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_from_node_skips_earlier_nodes(): void
    {
        $automation = $this->buildLinearAutomation();
        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'status' => AutomationRun::STATUS_QUEUED,
            'context' => $context->all(),
            'is_test' => true,
        ]);

        $finalRun = $runner->executeFromNode($run, $context, 'log2');

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeKeys = $finalRun->nodeRuns()->pluck('node_key')->all();

        // Trigger and log1 are NOT replayed; only log2 onward.
        $this->assertNotContains('t', $nodeKeys);
        $this->assertNotContains('log1', $nodeKeys);
        $this->assertContains('log2', $nodeKeys);
        $this->assertContains('log3', $nodeKeys);
    }

    public function test_execute_from_node_fails_gracefully_for_unknown_node(): void
    {
        $automation = $this->buildLinearAutomation();

        $context = AutomationContext::make([], testMode: true);
        $runner = app(WorkflowRunner::class);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'status' => AutomationRun::STATUS_QUEUED,
            'context' => $context->all(),
            'is_test' => true,
        ]);

        $finalRun = $runner->executeFromNode($run, $context, 'ghost');

        $this->assertSame(AutomationRun::STATUS_FAILED, $finalRun->status);
        $this->assertStringContainsString("'ghost'", (string) $finalRun->error_message);
    }

    protected function buildLinearAutomation(): Automation
    {
        $automation = Automation::create(['name' => 'Linear', 'handle' => 'linear-'.uniqid()]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
        ]);
        foreach (['log1', 'log2', 'log3'] as $key) {
            AutomationNode::create([
                'automation_id' => $automation->id,
                'node_key' => $key,
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => $key],
            ]);
        }

        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'log1']);
        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'log1', 'to_node_key' => 'log2']);
        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'log2', 'to_node_key' => 'log3']);

        return $automation->fresh()->load(['nodes', 'edges']);
    }
}
