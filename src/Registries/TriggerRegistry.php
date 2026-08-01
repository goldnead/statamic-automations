<?php

namespace Goldnead\StatamicAutomations\Registries;

use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use InvalidArgumentException;

class TriggerRegistry
{
    /**
     * @var array<string, class-string<AutomationTrigger>>
     */
    protected array $triggers = [];

    /**
     * Pre-configured trigger instances, keyed by handle. Used by config-driven
     * triggers (e.g. the generic EventTrigger) where the instance carries its
     * own definition and a fresh `app($class)` would lose it.
     *
     * @var array<string, AutomationTrigger>
     */
    protected array $instances = [];

    public function register(string $handle, string $class): void
    {
        if (! is_subclass_of($class, AutomationTrigger::class)) {
            throw new InvalidArgumentException(
                "{$class} must implement ".AutomationTrigger::class
            );
        }

        $this->triggers[$handle] = $class;
    }

    /**
     * Register an already-configured trigger instance. The dispatcher will
     * reuse this exact object (preserving its definition) rather than
     * resolving a fresh, empty one from the container.
     */
    public function registerInstance(string $handle, AutomationTrigger $instance): void
    {
        $this->triggers[$handle] = $instance::class;
        $this->instances[$handle] = $instance;
    }

    public function has(string $handle): bool
    {
        return isset($this->triggers[$handle]);
    }

    /**
     * @return class-string<AutomationTrigger>|null
     */
    public function class(string $handle): ?string
    {
        return $this->triggers[$handle] ?? null;
    }

    public function instance(string $handle): ?AutomationTrigger
    {
        if (isset($this->instances[$handle])) {
            return $this->instances[$handle];
        }

        $class = $this->class($handle);

        return $class ? app($class) : null;
    }

    /**
     * @return array<string, class-string<AutomationTrigger>>
     */
    public function all(): array
    {
        return $this->triggers;
    }

    public function flush(): void
    {
        $this->triggers = [];
        $this->instances = [];
    }
}
