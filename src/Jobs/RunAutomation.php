<?php

namespace Goldnead\StatamicAutomations\Jobs;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\AiGenerateAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Wraps a WorkflowRunner execution in a queued job so triggers don't
 * block the request that produced the underlying event.
 */
class RunAutomation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Seconds the worker lets this job run. Left null (the worker default)
     * unless the automation contains `ai_generate` nodes, whose per-node
     * timeouts can add up to far more than a worker's default 60 s.
     */
    public ?int $timeout = null;

    public function __construct(
        public int $runId,
        /** @var array<string, mixed> */
        public array $context = [],
        public bool $testMode = false,
    ) {
        $this->onQueue(config('automations.queue', 'default'));

        if ($connection = config('automations.queue_connection')) {
            $this->onConnection($connection);
        }

        $this->timeout = $this->aiTimeoutBudget();
    }

    /**
     * Sum of every AI node's timeout plus a minute of slack, so the job is
     * never killed while a node is still legitimately waiting for the model.
     * The queue connection's `retry_after` must exceed this as well.
     */
    protected function aiTimeoutBudget(): ?int
    {
        $automation = AutomationRun::find($this->runId)?->resolveAutomation();
        if ($automation === null) {
            return null;
        }

        $total = 0;
        foreach ($automation->nodes as $node) {
            if ($node->type === AiGenerateAction::handle()) {
                $total += AiGenerateAction::timeoutFor($node->config ?? []) ?? 0;
            }
        }

        return $total > 0 ? $total + 60 : null;
    }

    public function handle(WorkflowRunner $runner): void
    {
        $run = AutomationRun::find($this->runId);

        if ($run === null) {
            return;
        }

        $context = AutomationContext::make($this->context, $this->testMode);

        $runner->execute($run, $context);
    }
}
