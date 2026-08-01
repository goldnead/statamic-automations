<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;

/**
 * Persists run / node-run records during automation execution and
 * applies redaction to the stored payloads.
 */
class RunLogger
{
    public function __construct(protected TokenResolver $tokens) {}

    /**
     * Stamp the run as RUNNING.
     *
     * `started_at` is stamped ONCE, on the first start. A run that resumes
     * after a Delay / Wait node comes back through here (see
     * {@see WorkflowRunner::resumeAfterNode()}); overwriting `started_at`
     * there would discard the real origin of the run, erase the wait from
     * the record, and leave `started_at == finished_at`.
     */
    public function startRun(AutomationRun $run): void
    {
        $run->forceFill([
            'status' => AutomationRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
        ])->save();
    }

    public function finishRun(AutomationRun $run, string $status, ?string $error = null): void
    {
        $finished = now();

        $run->forceFill([
            'status' => $status,
            'finished_at' => $finished,
            'duration_ms' => $run->started_at
                ? static::elapsedMs($run->started_at, $finished)
                : null,
            'error_message' => $error,
        ])->save();

        // Fire a throttled failure alert for failed, non-test runs.
        if ($status === AutomationRun::STATUS_FAILED && ! $run->is_test) {
            app(FailureAlerter::class)->notify($run, $error);
        }
    }

    /**
     * Milliseconds between two instants, never negative.
     *
     * Carbon 3 returns a SIGNED diff, so `$to->diffInMilliseconds($from)`
     * yields a NEGATIVE number whenever $from precedes $to — which is the
     * normal case for a start/finish pair. Computing in the natural
     * direction ($from → $to) gives the positive value; the clamp then
     * absorbs backwards clock adjustments (NTP steps) so a stored duration
     * can never be negative.
     */
    public static function elapsedMs(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return max(0, (int) round(Carbon::instance($from)->diffInMilliseconds(Carbon::instance($to))));
    }

    /**
     * Persist a node run with redacted input/output payloads.
     *
     * `$startedAt` is the instant the node actually began executing. The
     * caller must capture it BEFORE running the node — otherwise the only
     * thing measurable here is the time it takes to build this record,
     * which always rounds to 0 ms.
     */
    public function recordNodeRun(
        AutomationRun $run,
        string $nodeKey,
        string $nodeType,
        array $input,
        ?ActionResult $result = null,
        ?\Throwable $exception = null,
        ?\DateTimeInterface $startedAt = null,
    ): AutomationNodeRun {
        $finished = now();
        $started = $startedAt ? Carbon::instance($startedAt) : $finished->copy();

        $status = match (true) {
            $exception !== null => AutomationNodeRun::STATUS_FAILED,
            $result === null => AutomationNodeRun::STATUS_PENDING,
            $result->isFailed() => AutomationNodeRun::STATUS_FAILED,
            $result->isStopped() => AutomationNodeRun::STATUS_STOPPED,
            $result->isSkipped() => AutomationNodeRun::STATUS_SKIPPED,
            default => AutomationNodeRun::STATUS_SUCCESS,
        };

        return AutomationNodeRun::create([
            'automation_run_id' => $run->id,
            'node_key' => $nodeKey,
            'node_type' => $nodeType,
            'status' => $status,
            'input' => config('automations.runs.store_node_io', true)
                ? $this->tokens->redact($input)
                : null,
            'output' => $result && config('automations.runs.store_node_io', true)
                ? $this->tokens->redact($result->output)
                : null,
            'error_message' => $exception?->getMessage() ?? $result?->error,
            'started_at' => $started,
            'finished_at' => $finished,
            'duration_ms' => static::elapsedMs($started, $finished),
        ]);
    }
}
