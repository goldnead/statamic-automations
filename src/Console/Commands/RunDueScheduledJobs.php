<?php

namespace Goldnead\StatamicAutomations\Console\Commands;

use Goldnead\StatamicAutomations\Jobs\ResumeDelayedRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Illuminate\Console\Command;

/**
 * Finds AutomationScheduledJobs whose `due_at` has passed and dispatches a
 * ResumeDelayedRun for each. Registered on Laravel's scheduler (everyMinute)
 * by the ServiceProvider — this is what actually makes delayed steps fire.
 *
 * Claiming is atomic: a conditional UPDATE flips PENDING → QUEUED and only
 * the tick whose update affected exactly one row dispatches the job. This
 * guarantees exactly-once dispatch even when scheduler ticks overlap.
 */
class RunDueScheduledJobs extends Command
{
    use RunsForEachBrand;

    protected $signature = 'automations:run-due {--brand= : Restrict to one brand handle or id}';

    protected $description = 'Resume automation runs whose delay/wait window has elapsed.';

    public function handle(): int
    {
        // A scheduled run has no session and therefore no brand; without this
        // the fail-closed scope hides every row and the command silently
        // reports success while doing nothing.
        return $this->forEachBrand(fn (): int => $this->handleForBrand());
    }

    protected function handleForBrand(): int
    {
        $due = AutomationScheduledJob::query()
            ->where('status', AutomationScheduledJob::STATUS_PENDING)
            ->where('due_at', '<=', now())
            ->get();

        $dispatched = 0;

        foreach ($due as $job) {
            // Atomically claim the job. If another overlapping tick already
            // flipped it, the affected-row count is 0 and we skip it.
            $claimed = AutomationScheduledJob::query()
                ->where('id', $job->id)
                ->where('status', AutomationScheduledJob::STATUS_PENDING)
                ->update(['status' => AutomationScheduledJob::STATUS_QUEUED]);

            if ($claimed === 1) {
                ResumeDelayedRun::dispatch($job->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} due scheduled job(s).");

        return self::SUCCESS;
    }
}
