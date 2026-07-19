<?php

namespace Goldnead\StatamicAutomations\Registries;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Registry of dynamic `options_source` resolvers.
 *
 * A node's config field can declare `options_source: 'my.things'`; the CP
 * config form fetches `GET /cp/automations/api/options/my.things`, which this
 * registry resolves to a flat `[['value' => ..., 'label' => ...], ...]` list.
 *
 * Built-in Statamic sources (collections, sites, users, roles, …) are
 * registered here at boot exactly like a third party would register its own —
 * so extensions can ship nodes whose dropdowns are populated by their own data
 * with full parity to the built-ins. Unknown sources resolve to an empty list,
 * never a fatal error.
 */
class OptionSourceRegistry
{
    /**
     * @var array<string, callable|class-string>
     */
    protected array $sources = [];

    /**
     * Register a resolver for a source handle.
     *
     * The resolver is either a callable `fn (Request $request): array` or a
     * class-string that is resolvable from the container and is invokable
     * (`__invoke(Request): array`) or exposes `resolve(Request): array`.
     */
    public function register(string $handle, callable|string $resolver): void
    {
        if ($handle === '') {
            throw new InvalidArgumentException('An option source handle must not be empty.');
        }

        if (is_string($resolver) && ! is_callable($resolver) && ! class_exists($resolver)) {
            throw new InvalidArgumentException(
                "Option source [{$handle}] resolver class [{$resolver}] does not exist."
            );
        }

        $this->sources[$handle] = $resolver;
    }

    public function has(string $handle): bool
    {
        return isset($this->sources[$handle]);
    }

    /**
     * @return array<string, callable|class-string>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * Resolve a source to a normalised option list. Unknown source or any
     * resolver error yields an empty list — the picker just stays empty.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function resolve(string $handle, Request $request): array
    {
        $resolver = $this->sources[$handle] ?? null;

        if ($resolver === null) {
            return [];
        }

        try {
            $result = $this->invoke($resolver, $request);

            return $this->normalize($result);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function invoke(callable|string $resolver, Request $request): mixed
    {
        if (is_string($resolver) && ! is_callable($resolver)) {
            $instance = app($resolver);

            if (is_callable($instance)) {
                return $instance($request);
            }

            if (method_exists($instance, 'resolve')) {
                return $instance->resolve($request);
            }

            throw new InvalidArgumentException(
                'Option source class must be invokable or expose resolve(Request).'
            );
        }

        return $resolver($request);
    }

    /**
     * Coerce loose resolver output into the canonical option shape. Accepts
     * a list of {value,label} maps, a list of plain scalars, or an assoc
     * value=>label map.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function normalize(mixed $result): array
    {
        if (! is_iterable($result)) {
            return [];
        }

        $options = [];

        foreach ($result as $key => $item) {
            if (is_array($item) && array_key_exists('value', $item)) {
                $options[] = [
                    'value' => (string) $item['value'],
                    'label' => (string) ($item['label'] ?? $item['value']),
                ];

                continue;
            }

            if (is_scalar($item)) {
                // Assoc map (value => label) or plain list of scalars.
                $isAssoc = ! is_int($key);
                $options[] = [
                    'value' => (string) ($isAssoc ? $key : $item),
                    'label' => (string) $item,
                ];
            }
        }

        return $options;
    }
}
