<?php

namespace Goldnead\StatamicAutomations\Facades;

use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\OptionSourceRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * Public extensibility API. Third-party addons register their own nodes and
 * data sources through these methods (see docs/extending.md).
 *
 * @method static \Goldnead\StatamicAutomations\Automations registerAction(string $handleOrClass, ?string $class = null)
 * @method static \Goldnead\StatamicAutomations\Automations registerTrigger(string $handleOrClass, ?string $class = null)
 * @method static \Goldnead\StatamicAutomations\Automations registerLogicNode(string $handleOrClass, ?string $class = null)
 * @method static \Goldnead\StatamicAutomations\Automations registerOptionSource(string $handle, callable|string $resolver)
 * @method static \Goldnead\StatamicAutomations\Automations registerEventTrigger(string $eventClass, array $definition)
 * @method static \Goldnead\StatamicAutomations\Automations registerBuiltIn(string $handle)
 * @method static array describe(string $class, ?string $expectedKind = null)
 * @method static \Goldnead\StatamicAutomations\Automations bootEventTriggersFromConfig()
 * @method static array eventTriggers()
 * @method static \Goldnead\StatamicAutomations\Automations trigger(string $handle, string $class)
 * @method static \Goldnead\StatamicAutomations\Automations action(string $handle, string $class)
 * @method static \Goldnead\StatamicAutomations\Automations node(string $handle, string $class)
 * @method static bool isBuiltIn(string $handle)
 * @method static TriggerRegistry triggers()
 * @method static ActionRegistry actions()
 * @method static NodeRegistry nodes()
 * @method static OptionSourceRegistry optionSources()
 */
class Automations extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'automations';
    }
}
