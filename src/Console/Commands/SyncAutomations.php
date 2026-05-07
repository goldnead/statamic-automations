<?php

namespace Goldnead\StatamicAutomations\Console\Commands;

use Goldnead\StatamicAutomations\Export\AutomationExporter;
use Goldnead\StatamicAutomations\Export\AutomationFileSync;
use Goldnead\StatamicAutomations\Export\AutomationImporter;
use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Sync automations between the database and resources/automations/*.json
 *
 * Usage:
 *   php artisan automations:sync                # interactive prompt for direction
 *   php artisan automations:sync --from=files   # import every file into the DB
 *   php artisan automations:sync --from=db      # export every DB automation to files
 *   php artisan automations:sync --watch        # re-run every 2 seconds (dev convenience)
 *   php artisan automations:sync --dry-run      # show what would happen, change nothing
 */
class SyncAutomations extends Command
{
    protected $signature = 'automations:sync
        {--from=auto : Where the source of truth lives — files | db | auto}
        {--strategy=db_wins : Conflict strategy — db_wins | file_wins}
        {--dry-run : Print what would happen without writing anything}
        {--watch : Re-run periodically (every 2 seconds) for development}';

    protected $description = 'Sync automations between the database and resources/automations/*.json';

    public function handle(
        Filesystem $files,
        AutomationFileSync $sync,
        AutomationImporter $importer,
        AutomationExporter $exporter,
    ): int {
        $watch = (bool) $this->option('watch');
        $dryRun = (bool) $this->option('dry-run');
        $from = $this->option('from');
        $strategy = $this->option('strategy');

        do {
            $this->runOnce($files, $sync, $importer, $exporter, $from, $strategy, $dryRun);

            if ($watch) {
                $this->line('  ↻ watching… (Ctrl+C to stop)');
                sleep(2);
            }
        } while ($watch);

        return self::SUCCESS;
    }

    protected function runOnce(
        Filesystem $files,
        AutomationFileSync $sync,
        AutomationImporter $importer,
        AutomationExporter $exporter,
        string $from,
        string $strategy,
        bool $dryRun,
    ): void {
        $direction = $from === 'auto' ? $this->detectDirection() : $from;

        match ($direction) {
            'files' => $this->syncFromFiles($files, $sync, $importer, $strategy, $dryRun),
            'db' => $this->syncFromDb($sync, $exporter, $dryRun),
            default => $this->warn("Unknown direction '{$direction}'. Use --from=files or --from=db."),
        };
    }

    /**
     * If files exist but DB is empty, default to importing files.
     * If DB has automations but no files exist, default to exporting.
     * Otherwise prompt the user.
     */
    protected function detectDirection(): string
    {
        $hasFiles = ! empty(app(AutomationFileSync::class)->listFiles());
        $hasDbRows = Automation::query()->exists();

        if ($hasFiles && ! $hasDbRows) {
            return 'files';
        }
        if ($hasDbRows && ! $hasFiles) {
            return 'db';
        }
        if (! $hasFiles && ! $hasDbRows) {
            $this->info('Nothing to sync — no files and no automations in the DB.');

            return 'noop';
        }

        $choice = $this->choice(
            'Both DB and files have automations. Which direction?',
            ['files → db (import)', 'db → files (export)', 'cancel'],
            0,
        );

        return match ($choice) {
            'files → db (import)' => 'files',
            'db → files (export)' => 'db',
            default => 'noop',
        };
    }

    protected function syncFromFiles(
        Filesystem $files,
        AutomationFileSync $sync,
        AutomationImporter $importer,
        string $strategy,
        bool $dryRun,
    ): void {
        $entries = $sync->listFiles();
        if (empty($entries)) {
            $this->info('No files in the configured automation folder.');

            return;
        }

        $this->info("Importing {$dryRun} from " . count($entries) . ' files…');

        foreach ($entries as $entry) {
            $existing = Automation::where('handle', $entry['handle'])->first();
            $payload = json_decode($files->get($entry['path']), true);

            if (! is_array($payload)) {
                $this->warn("  · {$entry['handle']}.json — invalid JSON, skipped");
                continue;
            }

            if ($existing && $strategy === 'db_wins') {
                $this->line("  · {$entry['handle']} — DB wins, file ignored");
                continue;
            }

            if ($dryRun) {
                $this->line("  · {$entry['handle']} — would " . ($existing ? 'replace' : 'create'));
                continue;
            }

            // file_wins → delete the existing DB row first so the
            // importer treats this as a fresh import without handle
            // suffix collisions.
            if ($existing && $strategy === 'file_wins') {
                $existing->delete();
            }

            $result = $importer->import($payload);

            if (! empty($result['warnings'])) {
                foreach ($result['warnings'] as $w) {
                    $this->warn("    ⚠ {$w}");
                }
            }

            $this->line("  ✓ {$entry['handle']} → {$result['automation']->handle}");
        }
    }

    protected function syncFromDb(
        AutomationFileSync $sync,
        AutomationExporter $exporter,
        bool $dryRun,
    ): void {
        $automations = Automation::with(['nodes', 'edges'])->get();

        if ($automations->isEmpty()) {
            $this->info('No automations in the DB to export.');

            return;
        }

        $this->info("Exporting {$automations->count()} automations to files…");

        foreach ($automations as $automation) {
            if ($dryRun) {
                $this->line("  · {$automation->handle} — would write {$sync->path($automation->handle)}");
                continue;
            }

            $path = $sync->exportToFile($automation);
            $this->line("  ✓ {$automation->handle} → {$path}");
        }
    }
}
