<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Pauses the run until a set of conditions is met, re-checking on a
 * configurable interval. Reuses the engine's wait/resume machinery: when
 * the conditions are not yet met it returns a wait result, the run is
 * parked, and the resumer re-executes this node after the interval.
 */
class WaitUntilNode implements AutomationLogicNode
{
    public static function handle(): string
    {
        return 'wait_until';
    }

    public static function label(): string
    {
        return 'Wait Until';
    }

    public static function description(): ?string
    {
        return 'Pauses until the configured conditions are met, re-checking on an interval.';
    }

    public static function group(): string
    {
        return 'Logic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    /**
     * Unlike Delay (which just needs the clock to elapse), a parked
     * Wait Until must be RE-EVALUATED when its scheduled recheck fires —
     * the condition may still be false, in which case it should park
     * again for another interval rather than let the run fall through.
     * Read by {@see \Goldnead\StatamicAutomations\Engine\WorkflowRunner::resumeAfterNode()}.
     */
    public static function reexecuteOnResume(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'mode',
                'label' => 'Match mode',
                'type' => 'select',
                'options' => [
                    ['value' => 'all', 'label' => 'All conditions must match'],
                    ['value' => 'any', 'label' => 'Any condition matches'],
                ],
                'default' => 'all',
                'required' => true,
            ],
            [
                'handle' => 'conditions',
                'label' => 'Conditions',
                'type' => 'condition_list',
                'required' => true,
            ],
            [
                'handle' => 'recheck_minutes',
                'label' => 'Re-check every (minutes)',
                'type' => 'number',
                'default' => 5,
            ],
        ];
    }

    public static function evaluate(
        AutomationContext $context,
        array $config,
        ConditionEvaluator $evaluator,
    ): ActionResult {
        $conditions = $config['conditions'] ?? [];
        $mode = $config['mode'] ?? ConditionEvaluator::MODE_ALL;

        if ($evaluator->evaluate($conditions, $context, $mode)) {
            return ActionResult::success(['waited' => false, 'matched' => true]);
        }

        // In test mode we never actually pause.
        if ($context->isTestMode()) {
            return ActionResult::success(['waited' => true, 'matched' => false, 'note' => 'Test mode — not paused.']);
        }

        $minutes = max(1, (int) ($config['recheck_minutes'] ?? 5));

        return ActionResult::wait(['seconds' => $minutes * 60], ['matched' => false, 'recheck_minutes' => $minutes]);
    }

    /**
     * {@see AutomationLogicNode} entry point — delegates to evaluate() with the
     * engine's ConditionEvaluator so the node satisfies the logic-node contract.
     */
    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return static::evaluate($context, $config, app(ConditionEvaluator::class));
    }
}
