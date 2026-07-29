<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * The one declaration of a node's output handles, and the resolver both
 * sides read it with.
 *
 * Until 1.7.0 a node's outputs were written twice: imperatively in PHP
 * (`SwitchNode::outputs()`, `ParallelNode::outputs()`, `LoopNode::outputs()`)
 * and again by hand in the canvas (`outputsFor()` in
 * `resources/js/composables/useAutoLayout.js`), including how each of them
 * reads `config.cases` / `config.branches`. The mirror was accurate, but a
 * third-party node could not participate in it at all: `NodeRegistry::describe()`
 * did not expose outputs, so whatever a custom node declared in PHP, the
 * canvas gave it exactly one `default` handle.
 *
 * The problem that makes this more than "expose the method" is that outputs
 * depend on config — a switch with three cases has different outputs from one
 * with five, and the canvas has to know that while the user is typing, without
 * a round trip. So what crosses to the frontend is not a list of handles but a
 * small declarative spec that both sides evaluate against the node's current
 * config. `resources/js/composables/useNodeOutputs.js` is the JS half of this
 * file; the two must produce the same handles in the same order, which
 * `tests/Feature/NodeOutputsContractTest.php` and `tests/js/node-outputs.test.mjs`
 * pin from either end.
 *
 * ## The spec
 *
 *     [
 *         'version'  => 1,
 *         'clauses'  => [ …first match wins… ],
 *         'primary'  => 'done',   // optional, see below
 *     ]
 *
 * A clause is:
 *
 *     [
 *         'when'    => ['field' => 'mode', 'default' => 'inline', 'not' => ['inline']],
 *         'outputs' => [['handle' => 'default', 'label' => '']],
 *         'from'    => ['field' => 'branches', 'handle' => 'key', 'label' => 'value'],
 *         'append'  => [['handle' => 'default', 'label' => 'Default']],
 *     ]
 *
 * - `when` — omitted means "always". `is` / `not` hold the accepted /
 *   rejected values of a config field, compared as strings, with `default`
 *   standing in for a missing or empty value. The last clause should be
 *   unconditional; if no clause matches, the node has no outputs.
 * - `outputs` — a static list, evaluated first.
 * - `from` — derived from a `key_value` config field. `handle` and `label`
 *   each name the side of the pair to read (`key` or `value`).
 *   `handle_fallback` fills in for an empty handle (switch: a case with no
 *   output handle typed routes to `default`), `label_fallback: 'handle'`
 *   labels an unlabelled row with its own handle (parallel branches).
 * - `append` — a static list added after the derived ones.
 *
 * The result is deduplicated by handle, first occurrence winning — which is
 * how a switch whose cases already target `default` gets one `default` output
 * rather than two, and why the label of the first occurrence is the one shown.
 *
 * ## `primary`
 *
 * The handle that is the node's *continuation* — where "and then?" goes. It
 * is what Duplicate and insert-on-edge attach to. Without it they take the
 * first declared output, which on a loop means the copy lands inside the body
 * rather than after it. Optional: a branch or a fan-out has no single
 * continuation and declares none.
 *
 * ## Version
 *
 * `version` is the contract between this file and the canvas, not the
 * addon's version. A canvas that meets a spec numbered higher than it
 * understands must ignore it and fall back to a single `default` output —
 * i.e. behave like the pre-1.7.0 canvas does for any node it has no special
 * knowledge of — rather than guess at fields it does not know.
 */
final class NodeOutputs
{
    /**
     * The same `key_value` normalisation the nodes themselves use, so a
     * `from` clause reads `config.cases` exactly the way `SwitchNode::execute()`
     * does — assoc map, list of {key,value} pairs, or a JSON string.
     */
    use NormalizesKeyValue;

    /**
     * Wire-format version of the spec. Bump when a change to the clause
     * grammar would be misread by an older canvas.
     */
    public const VERSION = 1;

    /**
     * The output handle the engine takes when a node fails and is configured
     * to continue on error ({@see \Goldnead\StatamicAutomations\Engine\WorkflowRunner}).
     * Every node has it implicitly, so no spec declares it and nothing may
     * report an edge leaving it as unknown.
     */
    public const ERROR_HANDLE = 'error';

    /**
     * A spec with a fixed list of outputs, whatever the config.
     *
     * Accepts the loose shapes a node author is likely to write: a list of
     * handles (`['approved', 'rejected']`), a handle => label map, or the
     * canonical list of `['handle' => …, 'label' => …]` rows.
     *
     * @param  array<mixed>  $outputs
     * @return array<string, mixed>
     */
    public static function fixed(array $outputs, ?string $primary = null): array
    {
        return static::spec([['outputs' => static::normalizeList($outputs)]], $primary);
    }

    /**
     * @param  array<int, array<string, mixed>>  $clauses
     * @return array<string, mixed>
     */
    public static function spec(array $clauses, ?string $primary = null): array
    {
        $spec = ['version' => self::VERSION, 'clauses' => array_values($clauses)];

        if ($primary !== null && $primary !== '') {
            $spec['primary'] = $primary;
        }

        return $spec;
    }

    /**
     * The spec every node has unless it says otherwise: one unlabelled
     * continuation called `default`.
     *
     * @return array<string, mixed>
     */
    public static function defaultSpec(): array
    {
        // No `primary`: a single output is trivially the continuation, and
        // marking it would put the key on nearly every node in the library
        // for no reader.
        return static::fixed([['handle' => 'default', 'label' => '']]);
    }

    /**
     * The two-way split `FlowValidator` has required of any type ending in
     * `.branch` since the first release. Applied by the registry to such a
     * type when it declares nothing itself, so the rule lives in one place
     * instead of being mirrored into the canvas (which is where 1.5.5 put it).
     *
     * @return array<string, mixed>
     */
    public static function branchSpec(): array
    {
        return static::fixed([
            ['handle' => 'true', 'label' => 'True'],
            ['handle' => 'false', 'label' => 'False'],
        ]);
    }

    /**
     * Resolve a spec against a node's config.
     *
     * @param  array<string, mixed>|null  $spec
     * @param  array<string, mixed>  $config
     * @return array<int, array{handle: string, label: string, primary?: bool}>
     */
    public static function resolve(?array $spec, array $config = []): array
    {
        if (! is_array($spec) || empty($spec['clauses']) || ! is_array($spec['clauses'])) {
            return [];
        }

        if ((int) ($spec['version'] ?? self::VERSION) > self::VERSION) {
            return static::resolve(static::defaultSpec(), $config);
        }

        $clause = null;
        foreach ($spec['clauses'] as $candidate) {
            if (is_array($candidate) && static::clauseApplies($candidate, $config)) {
                $clause = $candidate;
                break;
            }
        }

        if ($clause === null) {
            return [];
        }

        $rows = static::normalizeList($clause['outputs'] ?? []);

        if (isset($clause['from']) && is_array($clause['from'])) {
            $rows = array_merge($rows, static::fromKeyValue($clause['from'], $config));
        }

        $rows = array_merge($rows, static::normalizeList($clause['append'] ?? []));

        $seen = [];
        $outputs = [];
        foreach ($rows as $row) {
            if ($row['handle'] === '' || isset($seen[$row['handle']])) {
                continue;
            }
            $seen[$row['handle']] = true;
            $outputs[] = $row;
        }

        $primary = isset($spec['primary']) ? (string) $spec['primary'] : null;
        if ($primary !== null) {
            foreach ($outputs as $i => $output) {
                if ($output['handle'] === $primary) {
                    $outputs[$i]['primary'] = true;
                }
            }
        }

        return $outputs;
    }

    /**
     * The resolved handles only — what the validator compares stored edges
     * against and what a node's `outputs()` returns.
     *
     * @param  array<string, mixed>|null  $spec
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public static function handles(?array $spec, array $config = []): array
    {
        return array_map(
            fn (array $output) => $output['handle'],
            static::resolve($spec, $config),
        );
    }

    /**
     * The node's continuation handle — its `primary` if it declares one,
     * otherwise its first output, otherwise null (a `stop`, or a `parallel`
     * with no branches configured yet).
     *
     * @param  array<string, mixed>|null  $spec
     * @param  array<string, mixed>  $config
     */
    public static function continuation(?array $spec, array $config = []): ?string
    {
        $outputs = static::resolve($spec, $config);

        foreach ($outputs as $output) {
            if (($output['primary'] ?? false) === true) {
                return $output['handle'];
            }
        }

        return $outputs[0]['handle'] ?? null;
    }

    /**
     * Coerce whatever a spec (or a legacy `outputs()`) hands back into the
     * canonical `[{handle, label}]` rows.
     *
     * @param  mixed  $outputs
     * @return array<int, array{handle: string, label: string}>
     */
    public static function normalizeList(mixed $outputs): array
    {
        if (! is_array($outputs)) {
            return [];
        }

        $rows = [];

        foreach ($outputs as $key => $value) {
            if (is_array($value)) {
                $handle = (string) ($value['handle'] ?? '');
                $rows[] = ['handle' => $handle, 'label' => (string) ($value['label'] ?? '')];

                continue;
            }

            // ['approved', 'rejected'] — a plain list of handles.
            if (is_int($key)) {
                $rows[] = ['handle' => (string) $value, 'label' => ''];

                continue;
            }

            // ['approved' => 'Approved'] — a handle => label map.
            $rows[] = ['handle' => (string) $key, 'label' => (string) $value];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $clause
     * @param  array<string, mixed>  $config
     */
    protected static function clauseApplies(array $clause, array $config): bool
    {
        $when = $clause['when'] ?? null;

        if (! is_array($when) || ! isset($when['field'])) {
            return true;
        }

        $raw = $config[$when['field']] ?? null;
        $value = is_scalar($raw) ? (string) $raw : '';
        if ($value === '') {
            $value = (string) ($when['default'] ?? '');
        }

        if (isset($when['is']) && is_array($when['is'])) {
            return in_array($value, array_map('strval', $when['is']), true);
        }

        if (isset($when['not']) && is_array($when['not'])) {
            return ! in_array($value, array_map('strval', $when['not']), true);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $config
     * @return array<int, array{handle: string, label: string}>
     */
    protected static function fromKeyValue(array $from, array $config): array
    {
        $pairs = static::normalizeKeyValue($config[$from['field'] ?? ''] ?? null);

        $handleSide = (string) ($from['handle'] ?? 'key');
        $labelSide = (string) ($from['label'] ?? 'value');
        $handleFallback = (string) ($from['handle_fallback'] ?? '');
        $labelFallback = (string) ($from['label_fallback'] ?? '');

        $rows = [];

        foreach ($pairs as $key => $value) {
            $side = fn (string $which) => $which === 'key'
                ? (string) $key
                : (is_scalar($value) ? (string) $value : '');

            $handle = $side($handleSide);
            if ($handle === '') {
                $handle = $handleFallback;
            }

            $label = $side($labelSide);
            if ($label === '' && $labelFallback === 'handle') {
                $label = $handle;
            }

            $rows[] = ['handle' => $handle, 'label' => $label];
        }

        return $rows;
    }
}
