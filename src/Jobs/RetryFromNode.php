<?php

namespace Goldnead\StatamicAutomations\Jobs;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resume an automation run starting from a specific node — used for
 * partial-from-node retries. The original run's context is replayed
 * and the runner continues forward from $nodeKey.
 */
class RetryFromNode implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $runId,
        public string $nodeKey,
        /** @var array<string, mixed> */
        public array $context = [],
        public bool $testMode = false,
    ) {
        $this->onQueue(config('automations.queue', 'default'));

        if ($connection = config('automations.queue_connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(WorkflowRunner $runner): void
    {
        $run = AutomationRun::find($this->runId);

        if ($run === null) {
            return;
        }

        $context = AutomationContext::make($this->context, $this->testMode);

        $runner->executeFromNode($run, $context, $this->nodeKey);
    }
}
