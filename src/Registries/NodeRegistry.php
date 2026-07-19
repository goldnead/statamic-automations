<?php

namespace Goldnead\StatamicAutomations\Registries;

use InvalidArgumentException;

/**
 * Unified node registry — stores trigger / action / logic nodes in one
 * place so the UI can fetch the entire node library through a single
 * service.
 */
class NodeRegistry
{
    /**
     * @var array<string, array{handle: string, class: class-string, kind: string, meta?: array<string, mixed>}>
     */
    protected array $nodes = [];

    /**
     * Register a node.
     *
     * The optional $meta lets a caller supply a pre-built, schema-rich
     * description directly instead of deriving it from the class's static
     * methods. This is what backs config-driven nodes (e.g. the generic
     * {@see \Goldnead\StatamicAutomations\Nodes\Triggers\EventTrigger}), where
     * a single class serves many distinct handles and therefore cannot expose
     * per-handle metadata via static methods. When $meta is null (the default)
     * the description is built from the class as before.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function register(string $handle, string $class, string $kind, ?array $meta = null): void
    {
        if (! in_array($kind, ['trigger', 'action', 'logic'], true)) {
            throw new InvalidArgumentException("Unknown node kind: {$kind}");
        }

        $entry = [
            'handle' => $handle,
            'class' => $class,
            'kind' => $kind,
        ];

        if ($meta !== null) {
            $entry['meta'] = $meta;
        }

        $this->nodes[$handle] = $entry;
    }

    public function has(string $handle): bool
    {
        return isset($this->nodes[$handle]);
    }

    /**
     * @return array{handle: string, class: class-string, kind: string}|null
     */
    public function get(string $handle): ?array
    {
        return $this->nodes[$handle] ?? null;
    }

    public function class(string $handle): ?string
    {
        return $this->nodes[$handle]['class'] ?? null;
    }

    public function kind(string $handle): ?string
    {
        return $this->nodes[$handle]['kind'] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_map(
            fn (array $entry) => $this->describe($entry['handle']),
            $this->nodes,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function byKind(string $kind): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $entry) => $entry['kind'] === $kind,
        ));
    }

    /**
     * Build a public, schema-rich description of a node.
     *
     * @return array<string, mixed>
     */
    public function describe(string $handle): array
    {
        $entry = $this->nodes[$handle] ?? null;

        if ($entry === null) {
            return [];
        }

        // A registration may carry a pre-built description (config-driven
        // nodes that cannot express per-handle metadata via static methods).
        if (isset($entry['meta']) && is_array($entry['meta'])) {
            return array_merge($entry['meta'], [
                'handle' => $entry['handle'],
                'kind' => $entry['kind'],
            ]);
        }

        /** @var class-string<\Goldnead\StatamicAutomations\Contracts\AutomationNode> $class */
        $class = $entry['class'];

        $description = [
            'handle' => $entry['handle'],
            'kind' => $entry['kind'],
            'label' => $class::label(),
            'description' => $class::description(),
            'group' => $class::group(),
            'schema' => $class::schema(),
            'supports_test_mode' => $class::supportsTestMode(),
        ];

        // Triggers expose outputSchema() via the AutomationTrigger contract;
        // actions/logic nodes that produce downstream-readable variables
        // (e.g. create_entry -> {{ node.entry.id }}) may optionally define
        // the same static method without it being part of their contract.
        // The token inserter (Task 2.3) reads this for every node kind.
        if (method_exists($class, 'outputSchema')) {
            $description['output_schema'] = $class::outputSchema();
        }

        return $description;
    }

    /**
     * Reset the registry — primarily for tests.
     */
    public function flush(): void
    {
        $this->nodes = [];
    }
}
