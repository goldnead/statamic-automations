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

        $this->syncPath = sys_get_temp_dir().'/statamic-automations-sync-'.uniqid();
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

    public function test_update_strategy_updates_the_existing_automation_in_place(): void
    {
        $existing = $this->seedAutomation('alpha');
        $existing->forceFill(['enabled' => true])->save();

        File::put("{$this->syncPath}/alpha.json", json_encode([
            'schema_version' => 1,
            'automation' => ['name' => 'Alpha from file', 'handle' => 'alpha'],
            'requires' => [],
            'nodes' => [
                ['node_key' => 't', 'type' => 'manual'],
                ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'from file']],
            ],
            'edges' => [
                ['from_node_key' => 't', 'to_node_key' => 'log'],
            ],
        ]));

        $this->artisan('automations:sync', ['--from' => 'files', '--strategy' => 'update'])
            ->expectsOutputToContain('alpha → alpha (updated)')
            ->assertExitCode(0);

        $this->assertSame(1, Automation::count());
        $after = Automation::with('nodes')->find($existing->id);
        $this->assertSame('Alpha from file', $after->name);
        $this->assertTrue((bool) $after->enabled);
        $this->assertSame('from file', $after->nodes->firstWhere('node_key', 'log')->config['message']);

        // A second run over the same file (every 2s with --watch) changes nothing.
        $version = (int) $after->version;

        $this->artisan('automations:sync', ['--from' => 'files', '--strategy' => 'update'])
            ->expectsOutputToContain('alpha → alpha (unchanged)')
            ->assertExitCode(0);

        $this->assertSame($version, (int) Automation::find($existing->id)->version);
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
