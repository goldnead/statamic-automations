<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\DeclaresOutputs;
use Goldnead\StatamicAutomations\Support\NodeOutputs;

class StopNode implements AutomationLogicNode
{
    use DeclaresOutputs;

    /**
     * A terminal node: the run ends here, so there is nothing to continue
     * to and no handle to offer. This is what the canvas used to know as a
     * hard-coded `terminalTypes: ['stop']`.
     *
     * @return array<string, mixed>
     */
    public static function outputSpec(): array
    {
        return NodeOutputs::fixed([]);
    }

    public static function handle(): string
    {
        return 'stop';
    }

    public static function label(): string
    {
        return 'Stop Flow';
    }

    public static function description(): ?string
    {
        return 'Ends the automation immediately with status "stopped".';
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
                'handle' => 'reason',
                'label' => 'Reason',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ActionResult::stopped($config['reason'] ?? 'Stopped by Stop Node.');
    }
}
