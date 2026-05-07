<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Export\AutomationExporter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AutomationExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_full_graph(): void
    {
        $automation = Automation::create(['name' => 'My Flow', 'handle' => 'my-flow', 'description' => 'A test flow']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
            'config' => [],
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'log',
            'type' => 'add_log_entry',
            'config' => ['message' => 'hi'],
            'position_x' => 280,
            'position_y' => 0,
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'log',
        ]);

        $payload = app(AutomationExporter::class)->toArray($automation->fresh(['nodes', 'edges']));

        $this->assertSame(AutomationExporter::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('My Flow', $payload['automation']['name']);
        $this->assertSame('my-flow', $payload['automation']['handle']);
        $this->assertCount(2, $payload['nodes']);
        $this->assertCount(1, $payload['edges']);
        $this->assertSame('manual', $payload['nodes'][0]['type']);
    }

    public function test_detects_leadhub_requirement(): void
    {
        $automation = Automation::create(['name' => 'L', 'handle' => 'l']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'leadhub.lead_created',
        ]);

        $payload = app(AutomationExporter::class)->toArray($automation->fresh(['nodes', 'edges']));

        $this->assertContains('leadhub', $payload['requires']);
    }

    public function test_json_output_is_valid_json(): void
    {
        $automation = Automation::create(['name' => 'J', 'handle' => 'j']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
        ]);

        $json = app(AutomationExporter::class)->toJson($automation->fresh(['nodes', 'edges']));

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(AutomationExporter::SCHEMA_VERSION, $decoded['schema_version']);
    }
}
