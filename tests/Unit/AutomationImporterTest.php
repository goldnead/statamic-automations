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
    }
}
