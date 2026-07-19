<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Statamic\Facades\GlobalSet;

/**
 * Sets a single value on a Statamic global set.
 *
 * Side effects are gated behind `persist_statamic_changes` so test runs
 * never write.
 */
class SetGlobalValueAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'set_global_value';
    }

    public static function label(): string
    {
        return 'Set Global Value';
    }

    public static function description(): ?string
    {
        return 'Sets a single key on a Statamic global set (per site).';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'global_set',
                'label' => 'Global set',
                'type' => 'select',
                'options_source' => 'statamic.globals',
                'required' => true,
            ],
            [
                'handle' => 'site',
                'label' => 'Site',
                'type' => 'select',
                'options_source' => 'statamic.sites',
                'required' => false,
                'help' => 'Optional. Defaults to the default site.',
            ],
            [
                'handle' => 'key',
                'label' => 'Key',
                'type' => 'text',
                'required' => true,
                'help' => 'The global variable handle to set.',
            ],
            [
                'handle' => 'value',
                'label' => 'Value',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Tokens allowed, e.g. {{ entry.title }}.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'global' => [
                'handle' => 'string',
                'key' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $handle = $config['global_set'] ?? null;
        $key = $config['key'] ?? null;
        $value = $config['value'] ?? null;
        $site = $config['site'] ?? null;

        if (empty($handle)) {
            return ActionResult::failed('A global set is required.');
        }
        if (empty($key)) {
            return ActionResult::failed('A key is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => ['global_set' => $handle, 'site' => $site, 'key' => $key, 'value' => $value],
                'note' => 'Test mode — global value not changed.',
            ]);
        }

        $set = GlobalSet::findByHandle($handle) ?? GlobalSet::find($handle);
        if ($set === null) {
            return ActionResult::failed("Global set '{$handle}' not found.");
        }

        $variables = ! empty($site) && method_exists($set, 'in')
            ? $set->in($site)
            : $set->inDefaultSite();

        if ($variables === null) {
            return ActionResult::failed("Global set '{$handle}' has no values for the requested site.");
        }

        $variables->set($key, $value);
        $variables->save();

        return ActionResult::success([
            'global' => ['handle' => (string) $handle, 'key' => (string) $key],
        ]);
    }
}
