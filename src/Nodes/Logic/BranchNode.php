<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Support\ActionResult;

class BranchNode implements AutomationNode
{
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
}
