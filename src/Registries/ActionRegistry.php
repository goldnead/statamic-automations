<?php

namespace Goldnead\StatamicAutomations\Registries;

use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use InvalidArgumentException;

class ActionRegistry
{
    /**
     * @var array<string, class-string<AutomationAction>>
     */
    protected array $actions = [];

    public function register(string $handle, string $class): void
    {
        if (! is_subclass_of($class, AutomationAction::class)) {
            throw new InvalidArgumentException(
                "{$class} must implement ".AutomationAction::class
            );
        }

        $this->actions[$handle] = $class;
    }

    public function has(string $handle): bool
    {
        return isset($this->actions[$handle]);
    }

    /**
     * @return class-string<AutomationAction>|null
     */
    public function class(string $handle): ?string
    {
        return $this->actions[$handle] ?? null;
    }

    public function instance(string $handle): ?AutomationAction
    {
        $class = $this->class($handle);

        return $class ? app($class) : null;
    }

    /**
     * @return array<string, class-string<AutomationAction>>
     */
    public function all(): array
    {
        return $this->actions;
    }

    public function flush(): void
    {
        $this->actions = [];
    }
}
