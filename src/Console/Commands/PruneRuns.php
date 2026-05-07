<?php

namespace Goldnead\StatamicAutomations\Console\Commands;

use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Console\Command;

/**
 * Delete old automation runs according to the retention setting in
 * config/automations.php. Failed runs can optionally be kept longer.
 */
class PruneRuns extends Command
{
    protected $signature = 'automations:prune
        {--days= : Override the configured retention window}
        {--keep-failed-days= : Override how long failed runs are kept}
        {--dry-run : Show how many rows would be deleted without deleting}';

    protected $description = 'Delete automation runs older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('automations.runs.prune_after_days', 30));
        $keepFailedDays = $this->option('keep-failed-days') ?? config('automations.runs.keep_failed_runs_days');
        $dryRun = (bool) $this->option('dry-run');

        if ($days <= 0) {
            $this->info('Pruning is disabled (days <= 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $query = AutomationRun::query()->where('created_at', '<', $cutoff);
        if ($keepFailedDays !== null) {
            $failedCutoff = now()->subDays((int) $keepFailedDays);
            $query->where(function ($q) use ($failedCutoff) {
                $q->where('status', '!=', AutomationRun::STATUS_FAILED)
                    ->orWhere('created_at', '<', $failedCutoff);
            });
        }

        $count = $query->count();

        if ($dryRun) {
            $this->info("Would delete {$count} run(s) older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Deleted {$count} run(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
