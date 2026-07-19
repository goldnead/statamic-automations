<?php

namespace Goldnead\StatamicAutomations\Contracts;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Contract for logic / control-flow nodes (filter, branch, switch, loop,
 * parallel, stop, throttle, wait_until …).
 *
 * Logic nodes sit between triggers and actions: they never talk to an
 * external system, they steer the flow. Implementations return an
 * {@see ActionResult} whose `outputHandle` (e.g. `true` / `false`, a switch
 * case, a loop/parallel fan-out array) the WorkflowRunner already understands.
 *
 * Note on the built-ins: condition-driven nodes (Filter, Branch, WaitUntil)
 * historically expose a *static* `evaluate(AutomationContext, array,
 * ConditionEvaluator)` which the engine's NodeExecutor prefers when present.
 * They also implement this instance `execute()` (delegating to `evaluate()`)
 * so the contract holds for every logic node. A third-party logic node only
 * needs to implement `execute()`.
 */
interface AutomationLogicNode extends AutomationNode
{
    /**
     * Run the logic node against the current context.
     *
     * @param  array<string, mixed>  $config  Resolved node config, tokens replaced.
     */
    public function execute(AutomationContext $context, array $config): ActionResult;
}
