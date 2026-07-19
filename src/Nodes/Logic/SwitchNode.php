<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;

/**
 * Routes the flow to one of several labelled outputs by comparing a
 * token-resolved value against a map of case → output-handle. Falls
 * through to the "default" output when nothing matches.
 *
 * Modelled with a `key_value` cases field so it is fully editable in the
 * native CP config panel without a custom fieldtype.
 */
class SwitchNode implements AutomationNode
{
    use NormalizesKeyValue;

    /**
     * Output handles this node can route through: one per configured
     * case (the key_value's values — see {@see execute()}), plus a
     * trailing "default" for when nothing matches.
     *
     * @return array<int, string>
     */
    public static function outputs(array $config = []): array
    {
        $cases = static::normalizeKeyValue($config['cases'] ?? []);

        $handles = array_map(
            fn ($output) => (string) ($output ?: 'default'),
            array_values($cases),
        );
        $handles[] = 'default';

        return array_values(array_unique($handles));
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
                'help' => 'The value to switch on, e.g. {{ lead.status }}.',
            ],
            [
                'handle' => 'cases',
                'label' => 'Cases',
                'type' => 'key_value',
                'required' => true,
                'help' => 'Match value → output handle. Connect an edge from each output handle.',
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
