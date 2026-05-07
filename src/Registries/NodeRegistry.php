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
     * @var array<string, array{handle: string, class: class-string, kind: string}>
     */
    protected array $nodes = [];

    public function register(string $handle, string $class, string $kind): void
    {
        if (! in_array($kind, ['trigger', 'action', 'logic'], true)) {
            throw new InvalidArgumentException("Unknown node kind: {$kind}");
        }

        $this->nodes[$handle] = [
            'handle' => $handle,
            'class' => $class,
            'kind' => $kind,
        ];
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

        if ($entry['kind'] === 'trigger' && method_exists($class, 'outputSchema')) {
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
