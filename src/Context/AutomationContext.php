<?php

namespace Goldnead\StatamicAutomations\Context;

use ArrayAccess;

/**
 * Mutable, dot-addressable container for the data flowing through an
 * automation run. Triggers seed the context, action results add to it.
 */
class AutomationContext implements ArrayAccess
{
    /** @var array<string, mixed> */
    protected array $data;

    protected bool $testMode;

    /** @var array<string, mixed> */
    protected array $nodeOutputs = [];

    /**
     * @param  array<string, mixed>  $data  Initial seed data (e.g. from trigger).
     */
    public function __construct(array $data = [], bool $testMode = false)
    {
        $this->data = $data;
        $this->testMode = $testMode;
    }

    public static function make(array $data = [], bool $testMode = false): self
    {
        return new self($data, $testMode);
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    public function setTestMode(bool $value): self
    {
        $this->testMode = $value;

        return $this;
    }

    /**
     * Read a value via dot notation (e.g. "lead.email", "form.handle").
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Write a value via dot notation. Returns $this for chaining.
     */
    public function set(string $key, mixed $value): self
    {
        data_set($this->data, $key, $value);

        return $this;
    }

    public function has(string $key): bool
    {
        return data_get($this->data, $key, $sentinel = "\0__sentinel__\0") !== $sentinel;
    }

    /**
     * Merge another flat or nested array into the context.
     *
     * @param  array<string, mixed>  $values
     */
    public function merge(array $values): self
    {
        $this->data = array_replace_recursive($this->data, $values);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Record the output of a node so subsequent nodes can reference it
     * via tokens like {{ nodes.<key>.<field> }}.
     */
    public function recordNodeOutput(string $nodeKey, array $output): self
    {
        $this->nodeOutputs[$nodeKey] = $output;
        $this->set("nodes.{$nodeKey}", $output);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function nodeOutputs(): array
    {
        return $this->nodeOutputs;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    // ArrayAccess --------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $key = (string) $offset;
        $segments = explode('.', $key);
        $last = array_pop($segments);

        $target = &$this->data;
        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                return;
            }
            $target = &$target[$segment];
        }

        unset($target[$last]);
    }
}
