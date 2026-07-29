<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\DeclaresOutputs;
use Goldnead\StatamicAutomations\Support\NodeOutputs;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;

/**
 * Routes the flow to one of several labelled outputs by comparing a
 * token-resolved value against a map of case → output-handle. Falls
 * through to the "default" output when nothing matches.
 *
 * Modelled with a `key_value` cases field so it is fully editable in the
 * native CP config panel without a custom fieldtype.
 */
class SwitchNode implements AutomationLogicNode
{
    use DeclaresOutputs;
    use NormalizesKeyValue;

    /**
     * One output per configured case — the `cases` key_value maps a match
     * value (the label the user sees on the handle) to the output handle it
     * routes to, and a case with no handle typed falls to `default`. A
     * trailing `default` catches everything that matches nothing, deduped
     * away when a case already targets it.
     *
     * No `primary`: which case is "the continuation" is the user's business,
     * so Duplicate keeps attaching to the first one, as it did before 1.7.0.
     *
     * @return array<string, mixed>
     */
    public static function outputSpec(): array
    {
        return NodeOutputs::spec([[
            'from' => [
                'field' => 'cases',
                'handle' => 'value',
                'label' => 'key',
                'handle_fallback' => 'default',
            ],
            'append' => [['handle' => 'default', 'label' => 'Default']],
        ]]);
    }

    public static function handle(): string
    {
        return 'switch';
    }

    public static function label(): string
    {
        return 'Switch';
    }

    public static function description(): ?string
    {
        return 'Routes to one of several outputs based on a value.';
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
                'handle' => 'value',
                'label' => 'Value',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The value to switch on, e.g. {{ lead.status }}.',
            ],
            [
                'handle' => 'cases',
                'label' => 'Cases',
                'type' => 'key_value',
                'required' => true,
                'key_label' => 'Match value',
                'value_label' => 'Output handle',
                'help' => "One row per case: the value to match on the left, the output handle it routes to on the right. Each output handle you type here becomes a connectable output on the canvas — connect an edge from it to that case's branch.",
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $value = (string) ($config['value'] ?? '');
        $cases = $this->normalizeKeyValue($config['cases'] ?? []);

        foreach ($cases as $match => $output) {
            if ((string) $match === $value) {
                return ActionResult::success(
                    ['matched_case' => $match, 'value' => $value],
                    (string) ($output ?: 'default'),
                );
            }
        }

        return ActionResult::success(['matched_case' => null, 'value' => $value], 'default');
    }
}
