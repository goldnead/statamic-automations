<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Export\AutomationImporter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

class AutomationImporterTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(): array
    {
        return [
            'schema_version' => 1,
            'automation' => [
                'name' => 'Imported Flow',
                'handle' => 'imported-flow',
                'description' => null,
            ],
            'requires' => [],
            'nodes' => [
                ['node_key' => 't', 'type' => 'manual', 'config' => []],
                ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'hi']],
            ],
            'edges' => [
                ['from_node_key' => 't', 'to_node_key' => 'log'],
            ],
        ];
    }

    public function test_imports_a_well_formed_payload(): void
    {
        $result = app(AutomationImporter::class)->import($this->basePayload());

        $this->assertInstanceOf(Automation::class, $result['automation']);
        $this->assertSame('Imported Flow', $result['automation']->name);
        $this->assertCount(2, $result['automation']->nodes);
        $this->assertEmpty($result['warnings']);
        $this->assertEmpty($result['missing_node_types']);
    }

    public function test_rejects_unsupported_schema_version(): void
    {
        $payload = $this->basePayload();
        $payload['schema_version'] = 99;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported schema version 99');

        app(AutomationImporter::class)->import($payload);
    }

    public function test_rejects_payload_without_name(): void
    {
        $payload = $this->basePayload();
        unset($payload['automation']['name']);

        $this->expectException(InvalidArgumentException::class);

        app(AutomationImporter::class)->import($payload);
    }

    public function test_rejects_duplicate_node_keys(): void
    {
        $payload = $this->basePayload();
        $payload['nodes'][1]['node_key'] = 't';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate node_key');

        app(AutomationImporter::class)->import($payload);
    }

    public function test_rejects_edge_referencing_unknown_node(): void
    {
        $payload = $this->basePayload();
        $payload['edges'][0]['from_node_key'] = 'ghost';

        $this->expectException(InvalidArgumentException::class);

        app(AutomationImporter::class)->import($payload);
    }

    public function test_resolves_handle_collision_with_suffix(): void
    {
        Automation::create(['name' => 'Existing', 'handle' => 'imported-flow']);

        $result = app(AutomationImporter::class)->import($this->basePayload());

        $this->assertNotSame('imported-flow', $result['automation']->handle);
        $this->assertStringStartsWith('imported-flow-', $result['automation']->handle);
    }

    public function test_warns_about_unknown_node_types(): void
    {
        $payload = $this->basePayload();
        $payload['nodes'][1]['type'] = 'mystery.node';

        $result = app(AutomationImporter::class)->import($payload);

        $this->assertContains('mystery.node', $result['missing_node_types']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_imported_automation_starts_disabled(): void
    {
        $result = app(AutomationImporter::class)->import($this->basePayload());

        $this->assertFalse((bool) $result['automation']->enabled);
        $this->assertFalse($result['updated']);
    }

    public function test_update_strategy_replaces_the_graph_of_the_automation_with_the_same_handle(): void
    {
        $first = app(AutomationImporter::class)->import($this->basePayload())['automation'];
        $first->forceFill(['enabled' => true])->save();
        $keptUuid = $first->nodes->firstWhere('node_key', 't')->uuid;

        $payload = $this->basePayload();
        $payload['automation']['name'] = 'Imported Flow v2';
        $payload['nodes'][1] = ['node_key' => 'log2', 'type' => 'add_log_entry', 'config' => ['message' => 'v2']];
        $payload['edges'][0] = ['from_node_key' => 't', 'to_node_key' => 'log2'];

        $result = app(AutomationImporter::class)->import($payload, ['handle_strategy' => 'update']);
        $updated = $result['automation'];

        $this->assertTrue($result['updated']);
        $this->assertSame($first->id, $updated->id);
        $this->assertSame($first->uuid, $updated->uuid);
        $this->assertSame('imported-flow', $updated->handle);
        $this->assertSame('Imported Flow v2', $updated->name);
        $this->assertTrue((bool) $updated->enabled, 'An update keeps the enabled state.');
        $this->assertSame(['log2', 't'], $updated->nodes->pluck('node_key')->sort()->values()->all());
        $this->assertSame(['log2'], $updated->edges->pluck('to_node_key')->all());
        $this->assertSame($keptUuid, $updated->nodes->firstWhere('node_key', 't')->uuid, 'A surviving node keeps its uuid.');
        $this->assertSame(1, Automation::where('handle', 'like', 'imported-flow%')->count());
    }

    public function test_update_strategy_keeps_a_disabled_automation_disabled(): void
    {
        app(AutomationImporter::class)->import($this->basePayload());

        $result = app(AutomationImporter::class)->import($this->basePayload(), ['handle_strategy' => 'update']);

        $this->assertTrue($result['updated']);
        $this->assertFalse((bool) $result['automation']->enabled);
    }

    public function test_update_strategy_creates_when_no_automation_has_the_handle(): void
    {
        $result = app(AutomationImporter::class)->import($this->basePayload(), ['handle_strategy' => 'update']);

        $this->assertFalse($result['updated']);
        $this->assertSame('imported-flow', $result['automation']->handle);
        $this->assertFalse((bool) $result['automation']->enabled);
    }
}
