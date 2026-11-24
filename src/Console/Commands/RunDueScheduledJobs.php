<?php

namespace Goldnead\StatamicAutomations\Console\Commands;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\StatamicAutomations\Jobs\ResumeDelayedRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
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

    /** Dispatches that threw, summed over every brand of this run. */
    protected int $failedDispatches = 0;

    public function handle(): int
    {
        $this->failedDispatches = 0;

        // A scheduled run has no session and therefore no brand; without this
        // the fail-closed scope hides every row and the command silently
        // reports success while doing nothing.
        $exit = $this->forEachBrand(fn (): int => $this->handleForBrand());

        // Reported once at the end rather than returned per brand: a non-zero
        // exit from one brand would make `forEachBrand()` stop, and the brands
        // behind it would not be resumed in this tick.
        if ($this->failedDispatches > 0) {
            $this->error("{$this->failedDispatches} scheduled job(s) could not be dispatched and stay pending.");

            return self::FAILURE;
        }

        return $exit;
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
                try {
                    ResumeDelayedRun::dispatch($job->id);
                    $dispatched++;
                } catch (\Throwable $e) {
                    // The queue is unreachable. Nothing picks a QUEUED row up
                    // again, so hand the claim back: the next tick retries it.
                    AutomationScheduledJob::query()
                        ->where('id', $job->id)
                        ->where('status', AutomationScheduledJob::STATUS_QUEUED)
                        ->update(['status' => AutomationScheduledJob::STATUS_PENDING]);
                    report($e);
                    $this->failedDispatches++;
                }
            }
        }

        $this->info("Dispatched {$dispatched} due scheduled job(s).");

        return self::SUCCESS;
    }
}
