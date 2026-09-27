<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Nodes\Logic\FilterNode;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Walks an automation's graph and executes nodes one after another.
 *
 * The runner is intentionally synchronous for one run — concurrency
 * happens at the queue layer (RunAutomation job per run). Delay nodes
 * pause the run by creating an AutomationScheduledJob and returning.
 */
class WorkflowRunner
{
    /**
     * Internal walk result, never a run status: a Filter inside a loop body
     * did not match, so only the current item's pass ends. The loop that
     * drives the body swallows it and moves on to the next item; it cannot
     * reach the run row because {@see runFrom()} only returns it while
     * walking a loop body.
     */
    protected const ITERATION_ENDED = '_iteration_ended';

    /**
     * Engine key on the context: one line per inline Loop that set
     * `on_item_error: continue` and had items fail. Kept on the context
     * (not on this object) so it survives a Delay/Wait after the loop,
     * which persists the context and resumes in another process.
     */
    public const LOOP_FAILURES_KEY = '_loop_failures';

    public function __construct(
        protected NodeExecutor $executor,
        protected NodeRegistry $registry,
        protected RunLogger $logger,
        protected FlowValidator $validator,
        protected AutomationRepository $repository,
    ) {}

    /**
     * Create + return a new run record (without executing yet).
     */
    public function createRun(
        Automation $automation,
        AutomationContext $context,
        ?AutomationNode $triggerNode = null,
        ?string $subjectKey = null,
    ): AutomationRun {
        return AutomationRun::create([
            'automation_id' => $automation->exists ? $automation->id : null,
            'automation_uuid' => $automation->uuid,
            'trigger_node_key' => $triggerNode?->node_key,
            'trigger_type' => $triggerNode?->type,
            // Who this pass is about. Optional, and null for a trigger that
            // names no person — a scheduled sweep is a run without a subject,
            // not a run with an unknown one.
            'subject_key' => $subjectKey,
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
                'Automation failed validation: '.($errors[0]['message'] ?? 'unknown'),
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

        $this->logger->finishRun($run, $finalStatus, $this->loopFailureSummary($context));

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

        $this->logger->finishRun($run, $finalStatus, $this->loopFailureSummary($context));

        $this->touchLastRun($automation);

        return $run->fresh();
    }

    /**
     * Resume a run that was paused by a Delay/Wait node.
     *
     * By default the paused node is NOT re-executed — it is the delay
     * node that already fired, so the walk continues from the node AFTER
     * it (via its default outgoing edge), same as {@see executeFromNode()}
     * with $executeFirst = false.
     *
     * Some logic nodes need the opposite: a Wait Until must be
     * RE-EVALUATED on resume (its condition may still be false), so its
     * class opts in via a static `reexecuteOnResume(): true` method,
     * checked here — see {@see WaitUntilNode}.
     *
     * Returns the refreshed run model.
     */
    public function resumeAfterNode(
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
                executeFirst: $this->reexecutesOnResume($startNode),
            );
        } catch (\Throwable $e) {
            $this->logger->finishRun($run, AutomationRun::STATUS_FAILED, $e->getMessage());

            return $run->fresh();
        }

        // A chained delay pauses the run again — leave it waiting.
        if ($finalStatus === AutomationRun::STATUS_WAITING) {
            return $run->fresh();
        }

        $this->logger->finishRun($run, $finalStatus, $this->loopFailureSummary($context));

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
        /*
         * Ein Knoten muss wissen koennen, wovon er Teil ist.
         *
         * Der Kontext ist sonst reine Nutzlast — was der Ausloeser hineingelegt
         * hat und was Knoten daraus gemacht haben. Fuer den Serien-Ausstieg
         * reicht das nicht: der Sendeknoten muss vor jedem Schritt fragen
         * koennen "will diese Person diese Serie noch?", und dafuer braucht er
         * die UUID der Automation, in der er gerade steckt.
         *
         * Hier und nicht in den drei Einstiegen (execute, executeFromNode,
         * resumeAfterNode), weil alle drei durch walk() laufen — und ein
         * fortgesetzter Lauf nach zwei Tagen Wartezeit braucht es genauso wie
         * ein frischer.
         *
         * Unterstrich wie bei `_subject_key`: Motor-Daten, keine Nutzlast.
         */
        $context->set('_automation', [
            'uuid' => (string) $automation->uuid,
            'name' => (string) $automation->name,
        ]);
        $context->set('_subject_key', (string) ($run->subject_key ?? ''));

        $edges = $automation->edges;
        $nodes = $automation->nodes->keyBy('node_key');

        $current = $executeFirst
            ? $startNode
            : $this->nextNode($startNode, 'default', $edges, $nodes);
        $visited = [];

        return $this->runFrom($run, $automation, $current, $context, $edges, $nodes, $visited);
    }

    /**
     * Drive execution forward from $current along outgoing edges until the
     * graph naturally ends (no outgoing edge for the taken output) or a
     * terminal result (stopped / waiting) is hit.
     *
     * This is the single DFS driver used both for the top-level run and,
     * recursively, for each pass of an inline Loop node's body — so a
     * Stop node or a Delay/Wait node inside a loop body behaves exactly
     * like it would at the top level (it bubbles straight up and ends the
     * whole run, not just the current iteration).
     *
     * The one exception is a Filter that does not match inside a loop body
     * ($loopDepth > 0): it ends only the current item's pass and the loop
     * goes on with the next item. A Filter is a per-item question there
     * ("only the entries that are published"); ending the run on the first
     * non-match left every later item unprocessed. A Stop node still ends
     * the whole run: that is what it is for.
     *
     * @param  Collection  $edges
     * @param  Collection  $nodes
     * @param  array<string, bool>  $visited  Shared safety-net guard (by
     *                                        node_key) against runaway
     *                                        graphs; passed by reference so
     *                                        nested loop passes share it
     *                                        with the caller.
     * @param  int  $loopDepth  How many inline loop bodies this walk is
     *                          nested in; 0 at the top level.
     */
    protected function runFrom(
        AutomationRun $run,
        Automation $automation,
        ?AutomationNode $current,
        AutomationContext $context,
        $edges,
        $nodes,
        array &$visited,
        int $loopDepth = 0,
    ): string {
        $maxNodes = 1000; // safety net; cycles are blocked by validator

        while ($current !== null && count($visited) < $maxNodes) {
            $visited[$current->node_key] = true;

            // Capture the start BEFORE the node runs — this is the only
            // point at which the node's real execution time is measurable.
            $nodeStartedAt = now();

            $result = $this->executeWithRetries($current, $context);

            $nodeRun = $this->logger->recordNodeRun(
                $run,
                $current->node_key,
                $current->type,
                $context->all(),
                $result,
                startedAt: $nodeStartedAt,
            );

            $context->recordNodeOutput($current->node_key, $result->output);

            if ($result->isFailed()) {
                // A node may opt to continue the flow on error instead of
                // failing the whole run — routing down its "error" edge if
                // one exists, otherwise the default edge. Configure via the
                // reserved `_on_error: continue` key on the node.
                //
                // Without an error edge that means the NEXT step runs with
                // this node's output missing. Inside a loop, the Loop's own
                // `on_item_error: continue` is usually what is meant: it
                // ends the failed item's pass instead (driveInlineLoop()).
                if ($this->onErrorPolicy($current) === 'continue') {
                    $current = $this->nextNode($current, 'error', $edges, $nodes)
                        ?? $this->nextNode($current, 'default', $edges, $nodes);

                    continue;
                }

                throw new \RuntimeException(
                    "Node '{$current->node_key}' failed: ".($result->error ?? 'unknown')
                );
            }

            if ($result->isStopped()) {
                if ($loopDepth > 0 && $current->type === FilterNode::handle()) {
                    return self::ITERATION_ENDED;
                }

                return AutomationRun::STATUS_STOPPED;
            }

            if ($result->isWaiting()) {
                $this->scheduleWait($run, $automation, $current, $result, $context);

                $run->forceFill(['status' => AutomationRun::STATUS_WAITING])->save();

                return AutomationRun::STATUS_WAITING;
            }

            if ($result->isSkipped()) {
                // Skipped nodes do not advance the flow.
                return AutomationRun::STATUS_STOPPED;
            }

            // Inline Loop node: it never advances the flow itself on the
            // "loop" handle — the runner drives its body subgraph once per
            // resolved item, then continues via "done".
            if ($current->type === LoopNode::handle() && $result->outputHandle === LoopNode::OUTPUT_LOOP) {
                $bubbled = $this->driveInlineLoop($run, $automation, $current, $result, $context, $edges, $nodes, $visited, $loopDepth, $nodeRun);

                if ($bubbled !== null) {
                    return $bubbled;
                }

                $current = $this->nextNode($current, LoopNode::OUTPUT_DONE, $edges, $nodes);

                continue;
            }

            // Inline Parallel node: it declares its configured branch
            // handles and never advances the flow itself — the runner
            // drives EVERY subgraph wired to those handles to completion.
            // There is no single "joined" output to continue via
            // afterwards (each branch is its own path), so once fan-out
            // finishes normally the walk simply ends here.
            if ($current->type === ParallelNode::handle() && $result->outputHandle === ParallelNode::OUTPUT_FAN_OUT) {
                $bubbled = $this->driveInlineParallel($run, $automation, $current, $result, $edges, $nodes, $context, $visited, $loopDepth);

                if ($bubbled !== null) {
                    return $bubbled;
                }

                $current = null;

                continue;
            }

            $current = $this->nextNode($current, $result->outputHandle, $edges, $nodes);
        }

        return AutomationRun::STATUS_SUCCESS;
    }

    /**
     * Run the subgraph wired to a Loop node's "loop" output once per
     * resolved item, exposing `item` (or the configured item key), a bare
     * `index`, and `loop.count` / `loop.index` / `loop.first` / `loop.last`
     * in the run scope for the duration of each pass.
     *
     * The loop context is pushed/popped around each pass so nested loops
     * correctly shadow an outer loop's variables and restore them on exit
     * — the pop always runs (even if a body node throws) so the scope
     * never leaks past the loop.
     *
     * Returns null when all items were processed normally (the caller
     * should continue via the "done" output), or a terminal run status
     * ("stopped" / "waiting") bubbled up from inside the body.
     *
     * A Filter that does not match inside the body ends only that item's
     * pass (see {@see runFrom()}). A node that fails inside the body fails
     * the whole run, unless the Loop is set to `on_item_error: continue`:
     * then the failed item's pass ends, the next item runs, and the loop's
     * output gains `failed_items` and `failed` (index and error per item).
     * The run finishes with its normal status but carries a summary in its
     * error message, so the failure shows on the run and in the lists.
     */
    protected function driveInlineLoop(
        AutomationRun $run,
        Automation $automation,
        AutomationNode $loopNode,
        ActionResult $result,
        AutomationContext $context,
        $edges,
        $nodes,
        array &$visited,
        int $loopDepth = 0,
        ?AutomationNodeRun $loopNodeRun = null,
    ): ?string {
        $items = is_array($result->output['items'] ?? null) ? array_values($result->output['items']) : [];
        $itemKey = (string) ($result->output['item_key'] ?? 'item') ?: 'item';
        $continueOnError = ($result->output['on_item_error'] ?? LoopNode::ON_ITEM_ERROR_STOP) === LoopNode::ON_ITEM_ERROR_CONTINUE;
        $failed = [];
        $bodyStart = $this->nextNode($loopNode, LoopNode::OUTPUT_LOOP, $edges, $nodes);

        if ($bodyStart === null || empty($items)) {
            return null;
        }

        $count = count($items);

        // Push: remember whatever this scope key held before the loop
        // (e.g. an outer loop's own `item` / `loop` / `index`) so it can be
        // restored once this loop finishes — this is the shadow/restore
        // stack.
        $hadItem = $context->has($itemKey);
        $previousItem = $hadItem ? $context->get($itemKey) : null;
        $hadLoopVar = $context->has('loop');
        $previousLoopVar = $hadLoopVar ? $context->get('loop') : null;
        $hadIndexVar = $context->has('index');
        $previousIndexVar = $hadIndexVar ? $context->get('index') : null;

        $bubbled = null;

        try {
            foreach ($items as $index => $item) {
                $context->set($itemKey, $item);
                // Bare `index` alongside the nested `loop.*` keys — the
                // interface contract requires both to be resolvable inside
                // the loop body (e.g. {{ index }} and {{ loop.index }}).
                $context->set('index', $index);
                $context->set('loop', [
                    'count' => $count,
                    'index' => $index,
                    'first' => $index === 0,
                    'last' => $index === $count - 1,
                ]);

                if ($continueOnError) {
                    try {
                        $status = $this->runFrom($run, $automation, $bodyStart, $context, $edges, $nodes, $visited, $loopDepth + 1);
                    } catch (\Throwable $e) {
                        // The failed node is already in the run log with
                        // its own error; this only ends the item's pass.
                        $failed[] = ['index' => $index, 'error' => $e->getMessage()];

                        continue;
                    }
                } else {
                    $status = $this->runFrom($run, $automation, $bodyStart, $context, $edges, $nodes, $visited, $loopDepth + 1);
                }

                if ($status === self::ITERATION_ENDED) {
                    continue;
                }

                if ($status === AutomationRun::STATUS_STOPPED || $status === AutomationRun::STATUS_WAITING) {
                    $bubbled = $status;

                    break;
                }
            }
        } finally {
            // Pop: restore whatever the outer scope held (or clear it if
            // this was the outermost loop) so sibling/parent nodes never
            // see this loop's variables leak past its "done" output — even
            // if a body node threw (e.g. `_on_error` not "continue").
            if ($hadItem) {
                $context->set($itemKey, $previousItem);
            } else {
                unset($context[$itemKey]);
            }

            if ($hadLoopVar) {
                $context->set('loop', $previousLoopVar);
            } else {
                unset($context['loop']);
            }

            if ($hadIndexVar) {
                $context->set('index', $previousIndexVar);
            } else {
                unset($context['index']);
            }
        }

        if ($continueOnError) {
            $this->recordLoopFailures($loopNode, $result, $context, $failed, $count, $loopNodeRun);
        }

        return $bubbled;
    }

    /**
     * Put a `continue` loop's failed items where people and later steps see
     * them: on the loop's output (`{{ nodes.<loop>.failed_items }}` for the
     * steps after the loop, and the loop's row in the run log) and, when
     * any failed, as a line for the run's error message.
     *
     * @param  array<int, array{index: int, error: string}>  $failed
     */
    protected function recordLoopFailures(
        AutomationNode $loopNode,
        ActionResult $result,
        AutomationContext $context,
        array $failed,
        int $count,
        ?AutomationNodeRun $loopNodeRun,
    ): void {
        $summary = ['failed_items' => count($failed), 'failed' => $failed];

        $context->recordNodeOutput($loopNode->node_key, [...$result->output, ...$summary]);

        if ($loopNodeRun !== null && is_array($loopNodeRun->output)) {
            $loopNodeRun->forceFill([
                'output' => [...$loopNodeRun->output, ...app(TokenResolver::class)->redact($summary)],
            ])->save();
        }

        if ($failed === []) {
            return;
        }

        $name = $loopNode->label ?: $loopNode->node_key;
        $indexes = implode(', ', array_map(fn (array $f) => (string) $f['index'], $failed));

        $lines = (array) $context->get(self::LOOP_FAILURES_KEY, []);
        $lines[] = sprintf(
            "Loop '%s': %d of %d items failed and were skipped (index %s). First error: %s",
            $name,
            count($failed),
            $count,
            $indexes,
            $failed[0]['error'],
        );
        $context->set(self::LOOP_FAILURES_KEY, $lines);
    }

    /**
     * The run's error message for loops that skipped failed items, or null.
     * A run that fails outright gets its exception as the message instead.
     */
    protected function loopFailureSummary(AutomationContext $context): ?string
    {
        $lines = array_filter((array) $context->get(self::LOOP_FAILURES_KEY, []), 'is_string');

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * Run every subgraph wired to an inline Parallel node's declared
     * branch outputs to completion — not just the first connected edge.
     *
     * Branches run sequentially against the same shared context (this is
     * a scatter/gather shape, not OS-level concurrency — see
     * {@see ParallelNode}'s
     * class doc), mirroring how {@see driveInlineLoop()} drives one loop
     * pass at a time. A branch output with no wired edge is skipped.
     *
     * Returns null once every branch has run to its natural end (the
     * caller has nothing further to continue to — fan-out has no single
     * "joined" output), or a terminal run status ("stopped" / "waiting")
     * bubbled up from the first branch that hits one — remaining branches
     * are not started in that case, exactly like a loop pass that bubbles.
     *
     * @param  Collection  $edges
     * @param  Collection  $nodes
     * @param  array<string, bool>  $visited
     */
    protected function driveInlineParallel(
        AutomationRun $run,
        Automation $automation,
        AutomationNode $parallelNode,
        ActionResult $result,
        $edges,
        $nodes,
        AutomationContext $context,
        array &$visited,
        int $loopDepth = 0,
    ): ?string {
        $handles = is_array($result->output['branches'] ?? null) ? array_values($result->output['branches']) : [];

        foreach ($handles as $handle) {
            $branchStart = $this->nextNode($parallelNode, (string) $handle, $edges, $nodes);

            if ($branchStart === null) {
                continue;
            }

            $status = $this->runFrom($run, $automation, $branchStart, $context, $edges, $nodes, $visited, $loopDepth);

            // A Filter ending the loop item inside a branch ends the whole
            // item, other branches included, the way a Stop in a branch
            // ends the whole run.
            if ($status === AutomationRun::STATUS_STOPPED || $status === AutomationRun::STATUS_WAITING || $status === self::ITERATION_ENDED) {
                return $status;
            }
        }

        return null;
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

    /**
     * Whether a paused node must be re-executed (not just skipped past)
     * when its scheduled resume fires. See {@see resumeAfterNode()}.
     */
    protected function reexecutesOnResume(AutomationNode $node): bool
    {
        $class = $this->registry->class($node->type);

        return $class !== null
            && method_exists($class, 'reexecuteOnResume')
            && $class::reexecuteOnResume();
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
        AutomationContext $context,
    ): void {
        $waitUntil = $result->waitUntil ?? [];
        $dueAt = isset($waitUntil['due_at'])
            ? Carbon::parse($waitUntil['due_at'])
            : now()->addSeconds((int) ($waitUntil['seconds'] ?? 60));

        AutomationScheduledJob::create([
            'automation_id' => $automation->id,
            'automation_run_id' => $run->id,
            'node_key' => $node->node_key,
            'due_at' => $dueAt,
            'status' => AutomationScheduledJob::STATUS_PENDING,
            // Persist BOTH the node output and the live operational context
            // so the resumer can rebuild the full context (incl. nodes.*
            // tokens) instead of the redacted audit copy on the run row.
            'payload' => ['output' => $result->output, 'context' => $context->all()],
        ]);
    }

    protected function redactedContext(AutomationContext $context): array
    {
        $resolver = app(TokenResolver::class);

        return $resolver->redact($context->all());
    }
}
