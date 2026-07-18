<?php

namespace Goldnead\StatamicAutomations\Jobs;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resumes an automation run that was paused by a Delay / Wait node.
 *
 * The scheduled job's stored payload carries the live operational context
 * captured at pause time, so downstream nodes see the same data (incl.
 * nodes.* tokens) they would have if the delay never happened. The delay
 * node itself is NOT re-executed — the walk continues from the node after
 * it (see {@see WorkflowRunner::resumeAfterNode()}).
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

        if ($connection = config('automations.queue_connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(WorkflowRunner $runner): void
    {
        $job = AutomationScheduledJob::find($this->scheduledJobId);

        // Only PENDING (direct dispatch / back-compat) or QUEUED (claimed by
        // the due-job dispatcher) jobs are resumable. Anything else has been
        // dispatched, cancelled, or removed already.
        if ($job === null || ! in_array($job->status, [
            AutomationScheduledJob::STATUS_PENDING,
            AutomationScheduledJob::STATUS_QUEUED,
        ], true)) {
            return;
        }

        $run = AutomationRun::find($job->automation_run_id);

        if ($run === null) {
            $job->forceFill(['status' => AutomationScheduledJob::STATUS_DISPATCHED])->save();

            return;
        }

        $payload = $job->payload ?? [];
        // Prefer the persisted live context; fall back to the run's (redacted)
        // context for jobs created before context was persisted.
        $data = $payload['context'] ?? ($run->context ?? []);
        $context = AutomationContext::make($data, (bool) $run->is_test);

        // Mark dispatched BEFORE resuming so an overlapping tick or retry
        // cannot double-fire the downstream steps.
        $job->forceFill(['status' => AutomationScheduledJob::STATUS_DISPATCHED])->save();

        $runner->resumeAfterNode($run, $context, $job->node_key);
    }
}
