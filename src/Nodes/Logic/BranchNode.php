<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\DeclaresOutputs;
use Goldnead\StatamicAutomations\Support\NodeOutputs;

class BranchNode implements AutomationLogicNode
{
    use DeclaresOutputs;

    /**
     * The true/false split `FlowValidator` has required of this node — and
     * of any third-party type ending in `.branch` — since the first release.
     * Written down here now instead of only being enforced there and
     * mirrored in the canvas.
     *
     * No `primary`: neither side of a condition is "the continuation", so
     * Duplicate keeps attaching to `true`, as it has since 1.5.5.
     *
     * @return array<string, mixed>
     */
    public static function outputSpec(): array
    {
        return NodeOutputs::branchSpec();
    }

    public static function handle(): string
    {
        return 'branch';
    }

    public static function label(): string
    {
        return 'Branch';
    }

    public static function description(): ?string
    {
        return 'Splits the flow into a true / false path based on conditions.';
    }

    public static function group(): string
    {
        return 'Logic';
    }

    public static function supportsTestMode(): bool
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
        ];
    }

    public static function evaluate(
        AutomationContext $context,
        array $config,
        ConditionEvaluator $evaluator,
    ): ActionResult {
        $conditions = $config['conditions'] ?? [];
        $mode = $config['mode'] ?? ConditionEvaluator::MODE_ALL;

        $matched = $evaluator->evaluate($conditions, $context, $mode);

        return ActionResult::branch($matched, ['matched' => $matched]);
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
