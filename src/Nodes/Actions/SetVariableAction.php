<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;

/**
 * Writes one or more named variables into the run context under the
 * `vars` namespace, readable downstream as {{ vars.<name> }}.
 *
 * Values are token-resolved (by the executor) before they land here, so
 * this node also doubles as a "transform/compute" step.
 */
class SetVariableAction implements AutomationAction
{
    use NormalizesKeyValue;

    public static function handle(): string
    {
        return 'set_variable';
    }

    public static function label(): string
    {
        return 'Set Variable';
    }

    public static function description(): ?string
    {
        return 'Stores computed values under {{ vars.* }} for later nodes to read.';
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
                'handle' => 'variables',
                'label' => 'Variables',
                'type' => 'key_value',
                'required' => true,
                'tokenable' => true,
                'help' => 'Variable name → value. Values may contain tokens.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $vars = $this->normalizeKeyValue($config['variables'] ?? []);

        foreach ($vars as $name => $value) {
            $context->set("vars.{$name}", $value);
        }

        return ActionResult::success(['vars' => $vars]);
    }
}
