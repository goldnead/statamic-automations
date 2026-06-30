<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Walks an automation's graph and executes nodes one after another.
 *
 * The runner is intentionally synchronous for one run — concurrency
 * happens at the queue layer (RunAutomation job per run). Delay nodes
 * pause the run by creating an AutomationScheduledJob and returning.
 */
class WorkflowRunner
{
    public function __construct(
        protected NodeExecutor $executor,
        protected NodeRegistry $registry,
        protected RunLogger $logger,
        protected FlowValidator $validator,
        protected \Goldnead\StatamicAutomations\Contracts\AutomationRepository $repository,
    ) {
    }

    /**
     * Create + return a new run record (without executing yet).
     */
    public function createRun(
        Automation $automation,
        AutomationContext $context,
        ?AutomationNode $triggerNode = null,
    ): AutomationRun {
        return AutomationRun::create([
            'automation_id' => $automation->exists ? $automation->id : null,
            'automation_uuid' => $automation->uuid,
            'trigger_node_key' => $triggerNode?->node_key,
            'trigger_type' => $triggerNode?->type,
            'status' => AutomationRun::STATUS_QUEUED,
            'context' => config('automations.runs.store_full_context', true)
                ? $this->redactedContext($context)
                : ['site' => $context->get('site')],
            'is_test' => $context->isTestMode(),
        ]);
    }

    /**
     * Execute a previously created run from start to finish.
     *
     * Returns the final run model (refreshed).
     */
    public function execute(AutomationRun $run, AutomationContext $context): AutomationRun
    {
        $automation = $this->resolveAutomation($run);

        if ($automation === null) {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, 'Automation definition not found.');

            return $run->fresh();
        }

        // Bail out if the automation cannot be activated.
        $issues = $this->validator->validate($automation);
        $errors = array_filter($issues, fn ($i) => ($i['level'] ?? 'error') === 'error');
        if (! empty($errors)) {
            $this->logger->finishRun(
                $run,
                AutomationRun::STATUS_FAILED,
                'Automation failed validation: ' . ($errors[0]['message'] ?? 'unknown'),
            );

            return $run->fresh();
        }

        $this->logger->startRun($run);

        $startNode = $automation->nodes->firstWhere('node_key', $run->trigger_node_key);

        // If the run's recorded trigger_node_key doesn't actually point to
        // a trigger (e.g. the caller passed the wrong node), fall back to
        // the automation's actual trigger so the walk starts from the
        // correct entry point.
        if ($startNode === null || $this->registry->kind($startNode->type) !== 'trigger') {
            $startNode = $this->findTriggerNode($automation);
        }

        if ($startNode === null) {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, 'No trigger node found.');

            return $run->fresh();
        }

        // Trigger node itself is logged as a starting record (no execution).
        $this->logger->recordNodeRun(
            $run,
            $startNode->node_key,
            $startNode->type,
            $context->all(),
            ActionResult::success(['trigger' => $startNode->type]),
        );

        try {
            $finalStatus = $this->walk($run, $automation, $startNode, $context);
        } catch (\Throwable $e) {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, $e->getMessage());

            return $run->fresh();
        }

        // A waiting run is paused, not finished — keep finished_at null
        // so the resumer can pick it up later.
        if ($finalStatus === AutomationRun::STATUS_WAITING) {
            return $run->fresh();
        }

        $this->logger->finishRun($run, $finalStatus);

        $this->touchLastRun($automation);

        return $run->fresh();
    }

    /**
     * Resume execution from a specific node — used by partial-from-node
     * retries. The given node is executed (re-executed) and the runner
     * continues forward along outgoing edges.
     *
     * Unlike {@see execute()}, this never re-runs the trigger and only
     * walks forward from the chosen point.
     *
     * Returns the refreshed run model.
     */
    public function executeFromNode(
        AutomationRun $run,
        AutomationContext $context,
        string $nodeKey,
    ): AutomationRun {
        $automation = $this->resolveAutomation($run);

        if ($automation === null) {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, 'Automation definition not found.');

            return $run->fresh();
        }

        $startNode = $automation->nodes->firstWhere('node_key', $nodeKey);

        if ($startNode === null) {
            $this->logger->finishRun(
                $run,
                AutomationRun::STATUS_FAILED,
                "Cannot resume — node '{$nodeKey}' not found in automation.",
            );

            return $run->fresh();
        }

        $this->logger->startRun($run);

        try {
            $finalStatus = $this->walk(
                $run,
                $automation,
                $startNode,
                $context,
                executeFirst: true,
            );
        } catch (\Throwable $e) {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, $e->getMessage());

            return $run->fresh();
        }

        if ($finalStatus === AutomationRun::STATUS_WAITING) {
            return $run->fresh();
        }

        $this->logger->finishRun($run, $finalStatus);

        $this->touchLastRun($automation);

        return $run->fresh();
    }

    /**
     * Walk the graph from $startNode using DFS along outgoing edges.
     *
     * Returns the run's terminal status string.
     *
     * @param  bool  $executeFirst  When true, the start node itself is
     *                              executed (used for partial retries).
     *                              When false, the start node is treated
     *                              as the trigger and skipped.
     */
    protected function walk(
        AutomationRun $run,
        Automation $automation,
        AutomationNode $startNode,
        AutomationContext $context,
        bool $executeFirst = false,
    ): string {
        $edges = $automation->edges;
        $nodes = $automation->nodes->keyBy('node_key');

        $current = $executeFirst
            ? $startNode
            : $this->nextNode($startNode, 'default', $edges, $nodes);
        $visited = [];
        $maxNodes = 1000; // safety net; cycles are blocked by validator

        while ($current !== null && count($visited) < $maxNodes) {
            $visited[$current->node_key] = true;

            $result = $this->executeWithRetries($current, $context);

            $this->logger->recordNodeRun(
                $run,
                $current->node_key,
                $current->type,
                $context->all(),
                $result,
            );

            $context->recordNodeOutput($current->node_key, $result->output);

            if ($result->isFailed()) {
                // A node may opt to continue the flow on error instead of
                // failing the whole run — routing down its "error" edge if
                // one exists, otherwise the default edge. Configure via the
                // reserved `_on_error: continue` key on the node.
                if ($this->onErrorPolicy($current) === 'continue') {
                    $current = $this->nextNode($current, 'error', $edges, $nodes)
                        ?? $this->nextNode($current, 'default', $edges, $nodes);

                    continue;
                }

                throw new \RuntimeException(
                    "Node '{$current->node_key}' failed: " . ($result->error ?? 'unknown')
                );
            }

            if ($result->isStopped()) {
                return AutomationRun::STATUS_STOPPED;
            }

            if ($result->isWaiting()) {
                $this->scheduleWait($run, $automation, $current, $result);

                $run->forceFill(['status' => AutomationRun::STATUS_WAITING])->save();

                return AutomationRun::STATUS_WAITING;
            }

            if ($result->isSkipped()) {
                // Skipped nodes do not advance the flow.
                return AutomationRun::STATUS_STOPPED;
            }

            $current = $this->nextNode($current, $result->outputHandle, $edges, $nodes);
        }

        return AutomationRun::STATUS_SUCCESS;
    }

    /**
     * Execute a node, retrying transient failures up to the node's
     * configured attempt count. Retries are immediate (no backoff sleep) so
     * the queue worker is never blocked; use the Delay/Wait nodes for
     * time-spaced retries. Configure via the reserved `_retry_attempts` key.
     */
    protected function executeWithRetries(AutomationNode $node, AutomationContext $context): ActionResult
    {
        $extra = max(0, (int) data_get($node->config ?? [], '_retry_attempts', 0));

        $result = $this->executor->execute($node, $context);

        for ($attempt = 0; $attempt < $extra && $result->isFailed(); $attempt++) {
            $result = $this->executor->execute($node, $context);
        }

        return $result;
    }

    protected function onErrorPolicy(AutomationNode $node): string
    {
        return (string) data_get($node->config ?? [], '_on_error', 'fail');
    }

    protected function nextNode(
        AutomationNode $from,
        string $output,
        $edges,
        $nodes,
    ): ?AutomationNode {
        $edge = $edges->first(
            fn (AutomationEdge $e) => $e->from_node_key === $from->node_key
                && $e->from_output === $output,
        );

        if ($edge === null) {
            return null;
        }

        return $nodes->get($edge->to_node_key);
    }

    /**
     * Resolve a run's automation through the storage driver, with its graph
     * loaded. Falls back to the legacy Eloquent relation for old rows that
     * predate the uuid reference.
     */
    protected function resolveAutomation(AutomationRun $run): ?Automation
    {
        if ($automation = $run->resolveAutomation()) {
            return $automation;
        }

        $automation = $run->automation;
        $automation?->loadMissing(['nodes', 'edges']);

        return $automation;
    }

    /**
     * Persist the automation's last-run timestamp through whichever store
     * owns it (DB row vs flat file).
     */
    protected function touchLastRun(Automation $automation): void
    {
        $automation->last_run_at = now();

        if ($automation->exists) {
            $automation->save();

            return;
        }

        $this->repository->save($automation);
    }

    protected function findTriggerNode(Automation $automation): ?AutomationNode
    {
        return $automation->nodes->first(
            fn (AutomationNode $n) => $this->registry->kind($n->type) === 'trigger',
        );
    }

    protected function scheduleWait(
        AutomationRun $run,
        Automation $automation,
        AutomationNode $node,
        ActionResult $result,
    ): void {
        $waitUntil = $result->waitUntil ?? [];
        $dueAt = isset($waitUntil['due_at'])
            ? \Illuminate\Support\Carbon::parse($waitUntil['due_at'])
            : now()->addSeconds((int) ($waitUntil['seconds'] ?? 60));

        AutomationScheduledJob::create([
            'automation_id' => $automation->id,
            'automation_run_id' => $run->id,
            'node_key' => $node->node_key,
            'due_at' => $dueAt,
            'status' => AutomationScheduledJob::STATUS_PENDING,
            'payload' => $result->output,
        ]);
    }

    protected function redactedContext(AutomationContext $context): array
    {
        $resolver = app(TokenResolver::class);

        return $resolver->redact($context->all());
    }
}
