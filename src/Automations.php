<?php

namespace Goldnead\StatamicAutomations;

use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

/**
 * Public entry point for the Automations addon.
 *
 * Accessible via the Automations facade. Lets developers register their
 * own triggers, actions and generic nodes from any service provider.
 */
class Automations
{
    public function __construct(
        protected TriggerRegistry $triggers,
        protected ActionRegistry $actions,
        protected NodeRegistry $nodes,
    ) {
    }

    /**
     * Register a custom trigger.
     */
    public function trigger(string $handle, string $class): self
    {
        $this->triggers->register($handle, $class);
        $this->nodes->register($handle, $class, 'trigger');

        return $this;
    }

    /**
     * Register a custom action.
     */
    public function action(string $handle, string $class): self
    {
        $this->actions->register($handle, $class);
        $this->nodes->register($handle, $class, 'action');

        return $this;
    }

    /**
     * Register a custom logic node.
     *
     * Use this for filter / branch / stop / delay style nodes.
     */
    public function node(string $handle, string $class): self
    {
        $this->nodes->register($handle, $class, 'logic');

        return $this;
    }

    /**
     * Get the trigger registry.
     */
    public function triggers(): TriggerRegistry
    {
        return $this->triggers;
    }

    /**
     * Get the action registry.
     */
    public function actions(): ActionRegistry
    {
        return $this->actions;
    }

    /**
     * Get the unified node registry.
     */
    public function nodes(): NodeRegistry
    {
        return $this->nodes;
    }
}
