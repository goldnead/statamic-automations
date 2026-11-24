<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\AutomationRun;

/**
 * Closes a run whose queue job was aborted by the queue itself (timeout,
 * worker kill, exception outside the runner), so the runner's own catch never
 * ran. Goes through {@see RunLogger::finishRun()}, so the failure alert fires
 * like for any other failed run.
 *
 * Only runs that are still in flight are touched: a run that already finished,
 * or one parked on a delay (`waiting`), is not stranded.
 */
class FailsStrandedRuns
{
    public function __construct(protected RunLogger $logger) {}

    public function __invoke(int $runId, \Throwable $e): void
    {
        // Atomic: two closers (or a worker finishing at the same moment) cannot
        // both win, so the alert fires once and a finished run is never undone.
        $claimed = AutomationRun::query()
            ->whereKey($runId)
            ->whereIn('status', [AutomationRun::STATUS_QUEUED, AutomationRun::STATUS_RUNNING])
            ->update(['status' => AutomationRun::STATUS_FAILED]);

        if ($claimed !== 1) {
            return;
        }

        $run = AutomationRun::find($runId);

        if ($run === null) {
            return;
        }

        try {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, 'Queue job aborted: '.$e->getMessage());
        } catch (\Throwable $alertFailure) {
            // finishRun() sends the alert synchronously; the run is already
            // marked failed, and a throw here would only surface in the worker.
            report($alertFailure);
        }
    }
}
