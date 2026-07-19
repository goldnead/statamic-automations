<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;

class FlowValidatorTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_requires_exactly_one_trigger_node(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);

        $issues = app(FlowValidator::class)->validate($automation->fresh());

        $this->assertNotEmpty($issues);
        $this->assertSame('missing_trigger', $issues[0]['code']);
    }

    public function test_rejects_multiple_triggers(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't1',
            'type' => 'manual',
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't2',
            'type' => 'manual',
        ]);

        $issues = app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges']));

        $codes = array_column($issues, 'code');
        $this->assertContains('multiple_triggers', $codes);
    }

    public function test_rejects_edge_to_missing_node(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't1',
            'type' => 'manual',
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't1',
            'to_node_key' => 'ghost',
        ]);

        $issues = app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges']));

        $codes = array_column($issues, 'code');
        $this->assertContains('edge_invalid_to', $codes);
    }

    public function test_detects_cycle(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);

        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'a', 'type' => 'add_log_entry']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'b', 'type' => 'add_log_entry']);

        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'a', 'to_node_key' => 'b']);
        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'b', 'to_node_key' => 'a']);

        $issues = app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges']));

        $codes = array_column($issues, 'code');
        $this->assertContains('cycle_detected', $codes);
    }

    public function test_rejects_branch_with_invalid_outputs(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'b', 'type' => 'branch']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'x', 'type' => 'add_log_entry']);

        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 'b',
            'from_output' => 'maybe',
            'to_node_key' => 'x',
        ]);

        $issues = app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges']));

        $codes = array_column($issues, 'code');
        $this->assertContains('branch_invalid_output', $codes);
    }

    public function test_missing_required_field_reports_node_and_field(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
        // send_email requires to/subject/body; leave them empty.
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [],
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'mail',
        ]);

        $issues = app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges']));

        $missing = array_values(array_filter($issues, fn ($i) => ($i['code'] ?? null) === 'missing_required_config'));
        $this->assertNotEmpty($missing);
        foreach ($missing as $issue) {
            $this->assertSame('mail', $issue['node_key']);
            $this->assertArrayHasKey('field', $issue);
            $this->assertContains($issue['field'], ['to', 'subject', 'body']);
        }
    }

    public function test_valid_automation_returns_empty_issues(): void
    {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'log',
            'type' => 'add_log_entry',
            'config' => ['level' => 'info', 'message' => 'hi'],
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'log',
        ]);

        $issues = app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges']));

        $errors = array_filter($issues, fn ($i) => $i['level'] === 'error');
        $this->assertEmpty($errors, 'expected no errors but got ' . json_encode($issues));
    }
}
