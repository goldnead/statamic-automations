<?php

namespace Goldnead\StatamicAutomations\Facades;

use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\StatamicAutomations\Automations trigger(string $handle, string $class)
 * @method static \Goldnead\StatamicAutomations\Automations action(string $handle, string $class)
 * @method static \Goldnead\StatamicAutomations\Automations node(string $handle, string $class)
 * @method static TriggerRegistry triggers()
 * @method static ActionRegistry actions()
 * @method static NodeRegistry nodes()
 */
class Automations extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'automations';
    }
}
