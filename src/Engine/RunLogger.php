<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Persists run / node-run records during automation execution and
 * applies redaction to the stored payloads.
 */
class RunLogger
{
    public function __construct(protected TokenResolver $tokens)
    {
    }

    public function startRun(AutomationRun $run): void
    {
        $run->forceFill([
            'status' => AutomationRun::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();
    }

    public function finishRun(AutomationRun $run, string $status, ?string $error = null): void
    {
        $finished = now();

        $run->forceFill([
            'status' => $status,
            'finished_at' => $finished,
            'duration_ms' => $run->started_at
                ? (int) ($finished->diffInMilliseconds($run->started_at))
                : null,
            'error_message' => $error,
        ])->save();
    }

    /**
     * Persist a node run with redacted input/output payloads.
     */
    public function recordNodeRun(
        AutomationRun $run,
        string $nodeKey,
        string $nodeType,
        array $input,
        ?ActionResult $result = null,
        ?\Throwable $exception = null,
    ): AutomationNodeRun {
        $started = now();
        $finished = now();

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
            'duration_ms' => (int) $finished->diffInMilliseconds($started),
        ]);
    }
}
