<?php

namespace Goldnead\StatamicAutomations\Jobs;

use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Skeleton job that resumes an automation run that was paused by a
 * Delay node. Marked as a TODO for Phase F where the resume logic
 * is fully fleshed out.
 */
class ResumeDelayedRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $scheduledJobId)
    {
        $this->onQueue(config('automations.queue', 'default'));
    }

    public function handle(): void
    {
        $job = AutomationScheduledJob::find($this->scheduledJobId);

        if ($job === null || $job->status !== AutomationScheduledJob::STATUS_PENDING) {
            return;
        }

        // Phase F: resume the run from $job->node_key.
        // For now, mark the scheduled job as dispatched.
        $job->forceFill(['status' => AutomationScheduledJob::STATUS_DISPATCHED])->save();
    }
}
