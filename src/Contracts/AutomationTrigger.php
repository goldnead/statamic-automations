<?php

namespace Goldnead\StatamicAutomations\Contracts;

use Goldnead\StatamicAutomations\Context\AutomationContext;

interface AutomationTrigger extends AutomationNode
{
    /**
     * Output schema for the data picker. Describes what context fields
     * an event of this trigger type will produce.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array;

    /**
     * Decide whether this incoming event matches the trigger's
     * configuration on a particular automation.
     *
     * @param  object|array  $event  The original event payload (e.g. Statamic event object).
     * @param  array<string, mixed>  $config  Trigger node config (e.g. { form_handle, collection }).
     */
    public function matches(object|array $event, array $config): bool;

    /**
     * Build the initial AutomationContext for an event.
     *
     * @param  object|array  $event
     * @param  array<string, mixed>  $config
     */
    public function buildContext(object|array $event, array $config): AutomationContext;
}
