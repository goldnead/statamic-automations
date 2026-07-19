<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Support\ActionResult;

class DelayNode implements AutomationLogicNode
{
    public static function handle(): string
    {
        return 'delay';
    }

    public static function label(): string
    {
        return 'Delay';
    }

    public static function description(): ?string
    {
        return 'Pauses the automation for the configured amount of time.';
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
                'handle' => 'amount',
                'label' => 'Amount',
                'type' => 'number',
                'required' => true,
                'default' => 1,
            ],
            [
                'handle' => 'unit',
                'label' => 'Unit',
                'type' => 'select',
                'options' => [
                    ['value' => 'minutes', 'label' => 'Minutes'],
                    ['value' => 'hours', 'label' => 'Hours'],
                    ['value' => 'days', 'label' => 'Days'],
                ],
                'default' => 'minutes',
                'required' => true,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $amount = max(0, (int) ($config['amount'] ?? 0));
        $unit = $config['unit'] ?? 'minutes';

        $seconds = match ($unit) {
            'days' => $amount * 86400,
            'hours' => $amount * 3600,
            default => $amount * 60,
        };

        // In test mode, do not actually pause the run — just record
        // what would have happened.
        if ($context->isTestMode()) {
            return ActionResult::success([
                'would_wait_seconds' => $seconds,
                'unit' => $unit,
                'amount' => $amount,
            ]);
        }

        return ActionResult::wait(
            ['seconds' => $seconds],
            ['scheduled_seconds' => $seconds, 'unit' => $unit, 'amount' => $amount],
        );
    }
}
