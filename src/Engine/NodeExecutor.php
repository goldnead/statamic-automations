<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Resolves a single node's class, applies token replacement to its
 * config and executes it against the current context.
 *
 * Trigger nodes are not executed here — they are entry points and
 * their context is built before the runner kicks off.
 */
class NodeExecutor
{
    public function __construct(
        protected NodeRegistry $registry,
        protected TokenResolver $tokens,
        protected ConditionEvaluator $conditions,
    ) {
    }

    public function execute(AutomationNode $node, AutomationContext $context): ActionResult
    {
        $entry = $this->registry->get($node->type);

        if ($entry === null) {
            return ActionResult::failed("Unknown node type '{$node->type}'.");
        }

        if ($node->disabled) {
            return ActionResult::skipped('Node is disabled.');
        }

        /** @var class-string $class */
        $class = $entry['class'];
        $kind = $entry['kind'];
        $config = $this->tokens->resolve($node->config ?? [], $context);
        if (! is_array($config)) {
            $config = [];
        }

        try {
            return match ($kind) {
                'logic' => $this->executeLogicNode($class, $node, $context, $config),
                'action' => $this->executeAction($class, $context, $config),
                default => ActionResult::failed("Cannot execute node of kind '{$kind}'."),
            };
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function executeAction(string $class, AutomationContext $context, array $config): ActionResult
    {
        /** @var AutomationAction $action */
        $action = app($class);

        if ($context->isTestMode() && ! $class::supportsTestMode()) {
            return ActionResult::skipped('Action does not support test mode.');
        }

        return $action->execute($context, $config);
    }

    /**
     * Logic nodes are executed via a known interface — they are first-
     * class engine constructs (filter / branch / stop / delay), not
     * arbitrary user-registered classes.
     */
    protected function executeLogicNode(string $class, AutomationNode $node, AutomationContext $context, array $config): ActionResult
    {
        // Logic nodes implement a static execute(...) method that
        // receives the engine's evaluator + context for filter / branch
        // checks. Falls back to a simple instantiation otherwise.
        if (method_exists($class, 'evaluate')) {
            return $class::evaluate($context, $config, $this->conditions);
        }

        if (method_exists($class, 'execute')) {
            $instance = app($class);

            return $instance->execute($context, $config);
        }

        return ActionResult::failed("Logic node '{$class}' is not executable.");
    }
}
