<?php

namespace Goldnead\StatamicAutomations;

use Goldnead\StatamicAutomations\Licensing\LicenseManager;
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
    /**
     * Built-in node handles that are exempt from Pro licensing.
     * Populated via {@see registerBuiltIn()} during boot.
     *
     * @var array<string,bool>
     */
    protected array $builtInHandles = [];

    public function __construct(
        protected TriggerRegistry $triggers,
        protected ActionRegistry $actions,
        protected NodeRegistry $nodes,
        protected LicenseManager $license,
    ) {
    }

    /**
     * Mark a node handle as built-in. Built-in nodes are never gated by
     * the Pro license, even if `custom_actions_requires_pro` is true.
     */
    public function registerBuiltIn(string $handle): self
    {
        $this->builtInHandles[$handle] = true;

        return $this;
    }

    public function isBuiltIn(string $handle): bool
    {
        return isset($this->builtInHandles[$handle]);
    }

    /**
     * Register a trigger. Pro license is required for non-built-in
     * triggers when `features.custom_actions_requires_pro` is true.
     *
     * Failed gates simply skip registration — they don't throw — so a
     * package boot never crashes when the customer's license has lapsed.
     */
    public function trigger(string $handle, string $class): self
    {
        if (! $this->canRegister($handle)) {
            return $this;
        }

        $this->triggers->register($handle, $class);
        $this->nodes->register($handle, $class, 'trigger');

        return $this;
    }

    /**
     * Register an action. Subject to the same Pro gate as triggers.
     */
    public function action(string $handle, string $class): self
    {
        if (! $this->canRegister($handle)) {
            return $this;
        }

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
        if (! $this->canRegister($handle)) {
            return $this;
        }

        $this->nodes->register($handle, $class, 'logic');

        return $this;
    }

    public function triggers(): TriggerRegistry
    {
        return $this->triggers;
    }

    public function actions(): ActionRegistry
    {
        return $this->actions;
    }

    public function nodes(): NodeRegistry
    {
        return $this->nodes;
    }

    public function license(): LicenseManager
    {
        return $this->license;
    }

    /**
     * Decide whether a handle is allowed to be registered given the
     * current license state.
     */
    protected function canRegister(string $handle): bool
    {
        if ($this->isBuiltIn($handle)) {
            return true;
        }

        return $this->license->gates('custom_actions');
    }
}
