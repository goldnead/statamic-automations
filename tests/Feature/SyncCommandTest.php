<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

class SyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $syncPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncPath = sys_get_temp_dir() . '/statamic-automations-sync-' . uniqid();
        File::ensureDirectoryExists($this->syncPath);
        config()->set('automations.file_storage.path', $this->syncPath);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->syncPath)) {
            File::deleteDirectory($this->syncPath);
        }
        parent::tearDown();
    }

    public function test_db_to_files_writes_each_automation(): void
    {
        $this->seedAutomation('alpha');
        $this->seedAutomation('beta');

        $this->artisan('automations:sync', ['--from' => 'db'])
            ->expectsOutput('Exporting 2 automations to files…')
            ->assertExitCode(0);

        $this->assertFileExists("{$this->syncPath}/alpha.json");
        $this->assertFileExists("{$this->syncPath}/beta.json");
    }

    public function test_files_to_db_creates_automations_when_db_is_empty(): void
    {
        File::put("{$this->syncPath}/imported.json", json_encode([
            'schema_version' => 1,
            'automation' => ['name' => 'Imported', 'handle' => 'imported'],
            'requires' => [],
            'nodes' => [
                ['node_key' => 't', 'type' => 'manual'],
                ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'hi']],
            ],
            'edges' => [
                ['from_node_key' => 't', 'to_node_key' => 'log'],
            ],
        ]));

        $this->artisan('automations:sync', ['--from' => 'files'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('automations', ['name' => 'Imported']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedAutomation('only-in-db');

        $this->artisan('automations:sync', [
            '--from' => 'db',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist("{$this->syncPath}/only-in-db.json");
    }

    protected function seedAutomation(string $handle): Automation
    {
        $automation = Automation::create(['name' => ucfirst($handle), 'handle' => $handle]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'log',
            'type' => 'add_log_entry',
            'config' => ['message' => 'hi'],
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'log',
        ]);

        return $automation->fresh()->load(['nodes', 'edges']);
    }
}
