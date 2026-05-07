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
 * Wraps a WorkflowRunner execution in a queued job so triggers don't
 * block the request that produced the underlying event.
 */
class RunAutomation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
