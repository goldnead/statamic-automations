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

    public function register(string $handle, string $class): void
    {
        if (! is_subclass_of($class, AutomationTrigger::class)) {
            throw new InvalidArgumentException(
                "{$class} must implement " . AutomationTrigger::class
            );
        }

        $this->triggers[$handle] = $class;
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
    }
}
