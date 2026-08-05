<?php

namespace Goldnead\StatamicAutomations\Registries;

use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Triggers\EventTrigger;
use Goldnead\StatamicAutomations\Support\NodeOutputs;
use Goldnead\StatamicAutomations\Support\RestartPolicy;
use InvalidArgumentException;
use ReflectionMethod;

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
     * {@see EventTrigger}), where
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
                'schema' => $this->withCommonFields(
                    $entry['kind'],
                    is_array($entry['meta']['schema'] ?? null) ? $entry['meta']['schema'] : [],
                ),
                // A meta registration may declare outputs itself; a
                // config-driven trigger normally has the one continuation.
                'outputs' => isset($entry['meta']['outputs']) && is_array($entry['meta']['outputs'])
                    ? $entry['meta']['outputs']
                    : NodeOutputs::defaultSpec(),
            ]);
        }

        /** @var class-string<AutomationNode> $class */
        $class = $entry['class'];

        $description = [
            'handle' => $entry['handle'],
            'kind' => $entry['kind'],
            'label' => $class::label(),
            'description' => $class::description(),
            'group' => $class::group(),
            'schema' => $this->withCommonFields($entry['kind'], $class::schema()),
            'supports_test_mode' => $class::supportsTestMode(),
            // The node's output handles, as a spec the canvas resolves
            // against the node's live config. Present on every node, so the
            // canvas never has to know a node type by name.
            'outputs' => $this->outputSpec($entry['handle']),
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
     * Append the fields every node of a kind carries, whoever wrote the node.
     *
     * Today that is the two re-entry fields on a trigger. They are appended
     * here rather than written into each trigger class for three reasons: the
     * addon ships twenty-two triggers and integrations add more; a third-party
     * trigger would otherwise silently lack the setting; and the generic
     * {@see EventTrigger} serves
     * many handles from one class and has no per-handle static schema to write
     * them into.
     *
     * A node that declares a field of the same handle wins — nothing here
     * overwrites what a class said about itself.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array<string, mixed>>
     */
    protected function withCommonFields(string $kind, array $schema): array
    {
        if ($kind !== 'trigger') {
            return $schema;
        }

        $declared = array_filter(array_map(
            fn ($field) => is_array($field) ? ($field['handle'] ?? null) : null,
            $schema,
        ));

        foreach (RestartPolicy::triggerSchema() as $field) {
            if (! in_array($field['handle'], $declared, true)) {
                $schema[] = $field;
            }
        }

        return $schema;
    }

    /**
     * The output spec for a registered handle — the single declaration the
     * canvas, the validator and the node's own `outputs()` all read.
     *
     * Precedence, and the reason for each step:
     *
     * 1. `outputSpec()` on the class. The only form that can express
     *    config-dependent outputs to a frontend, because it is data rather
     *    than code.
     * 2. `outputs()` on the class, resolved under an empty config and
     *    serialised as fixed. A third-party node with a fixed set of handles
     *    gets full canvas support without knowing the spec grammar exists;
     *    one whose outputs vary by config is served approximately here and
     *    should declare `outputSpec()` instead.
     * 3. The `.branch` suffix. `FlowValidator` has required true/false of
     *    any such type since the first release, so a type that declares
     *    nothing gets the outputs the validator will hold it to. 1.5.5 fixed
     *    this by teaching the canvas the same suffix rule; the rule now
     *    lives here instead, and an explicit declaration overrides it.
     * 4. One unlabelled `default` continuation — every other node.
     *
     * @return array<string, mixed>
     */
    public function outputSpec(string $handle): array
    {
        $class = $this->class($handle);

        if ($class === null) {
            return NodeOutputs::defaultSpec();
        }

        if (method_exists($class, 'outputSpec')) {
            return $class::outputSpec();
        }

        if (method_exists($class, 'outputs')) {
            return NodeOutputs::fixed($this->callOutputs($class, []));
        }

        if (str_ends_with($handle, '.branch')) {
            return NodeOutputs::branchSpec();
        }

        return NodeOutputs::defaultSpec();
    }

    /**
     * The output handles a node of this type actually has under `$config` —
     * what `FlowValidator` compares an automation's stored edges against.
     *
     * A node declaring `outputs()` imperatively is asked directly (its answer
     * for this config beats the fixed serialisation in {@see outputSpec()});
     * everything else resolves its spec.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function outputsFor(string $handle, array $config = []): array
    {
        $class = $this->class($handle);

        if ($class !== null && ! method_exists($class, 'outputSpec') && method_exists($class, 'outputs')) {
            return array_values(array_filter(array_map(
                fn ($output) => is_array($output) ? (string) ($output['handle'] ?? '') : (string) $output,
                $this->callOutputs($class, $config),
            ), fn (string $out) => $out !== ''));
        }

        return NodeOutputs::handles($this->outputSpec($handle), $config);
    }

    /**
     * Call a node's `outputs()`, passing the config only when it takes one.
     *
     * `LoopNode::outputs()` took no argument until 1.7.0 and a third-party
     * node may well do the same; PHP tolerates the extra argument, but not
     * every static analyser or `__callStatic` shim does.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $config
     * @return array<mixed>
     */
    protected function callOutputs(string $class, array $config): array
    {
        $accepts = (new ReflectionMethod($class, 'outputs'))->getNumberOfParameters() > 0;

        $outputs = $accepts ? $class::outputs($config) : $class::outputs();

        return is_array($outputs) ? $outputs : [];
    }

    /**
     * Reset the registry — primarily for tests.
     */
    public function flush(): void
    {
        $this->nodes = [];
    }
}
