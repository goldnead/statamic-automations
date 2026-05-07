<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/**
 * Validates an automation's structure before activation or execution.
 *
 * Returns a normalized list of issues. Empty list = automation is valid.
 *
 * Each issue has the shape:
 *   { level: "error"|"warning", code: string, message: string, node_key?: string }
 */
class FlowValidator
{
    public function __construct(protected NodeRegistry $nodes)
    {
    }

    /**
     * @return array<int, array{level: string, code: string, message: string, node_key?: string}>
     */
    public function validate(Automation $automation): array
    {
        $issues = [];

        $nodes = $automation->nodes()->get();
        $edges = $automation->edges()->get();

        // 1) Trigger count and trigger isolation.
        $triggers = $nodes->filter(fn ($n) => ($this->nodes->kind($n->type) === 'trigger'));

        if ($triggers->count() === 0) {
            $issues[] = $this->error('missing_trigger', 'Automation must have a trigger node.');
        } elseif ($triggers->count() > 1) {
            $issues[] = $this->error('multiple_triggers', 'Automation must have exactly one trigger node.');
        }

        // 2) Edges must point to existing node keys.
        $nodeKeys = $nodes->pluck('node_key')->all();

        foreach ($edges as $edge) {
            if (! in_array($edge->from_node_key, $nodeKeys, true)) {
                $issues[] = $this->error(
                    'edge_invalid_from',
                    "Edge references missing source node '{$edge->from_node_key}'.",
                );
            }
            if (! in_array($edge->to_node_key, $nodeKeys, true)) {
                $issues[] = $this->error(
                    'edge_invalid_to',
                    "Edge references missing target node '{$edge->to_node_key}'.",
                );
            }
        }

        // 3) Trigger nodes may not have incoming edges.
        foreach ($triggers as $trigger) {
            $incoming = $edges->where('to_node_key', $trigger->node_key);
            if ($incoming->isNotEmpty()) {
                $issues[] = $this->error(
                    'trigger_incoming_edge',
                    "Trigger node '{$trigger->node_key}' must not have incoming edges.",
                    $trigger->node_key,
                );
            }
        }

        // 4) Branch nodes — outputs must be true / false, no other handles.
        foreach ($nodes as $node) {
            $registryEntry = $this->nodes->get($node->type);
            if ($registryEntry === null) {
                $issues[] = $this->error(
                    'unknown_node_type',
                    "Unknown node type '{$node->type}'.",
                    $node->node_key,
                );
                continue;
            }

            if ($node->type === 'branch' || str_ends_with($node->type, '.branch')) {
                $outgoing = $edges->where('from_node_key', $node->node_key);
                foreach ($outgoing as $edge) {
                    if (! in_array($edge->from_output, ['true', 'false'], true)) {
                        $issues[] = $this->error(
                            'branch_invalid_output',
                            "Branch node '{$node->node_key}' has invalid output handle '{$edge->from_output}'.",
                            $node->node_key,
                        );
                    }
                }
            }

            // 5) Required config fields.
            $issues = array_merge(
                $issues,
                $this->validateConfig($node, $registryEntry['class']),
            );
        }

        // 6) No cycles.
        if ($this->hasCycle($nodes->pluck('node_key')->all(), $edges->all())) {
            $issues[] = $this->error('cycle_detected', 'Automation contains a cycle.');
        }

        return $issues;
    }

    /**
     * @param  class-string  $class
     * @return array<int, array{level: string, code: string, message: string, node_key?: string}>
     */
    protected function validateConfig(\Goldnead\StatamicAutomations\Models\AutomationNode $node, string $class): array
    {
        $issues = [];
        $schema = method_exists($class, 'schema') ? $class::schema() : [];
        $config = $node->config ?? [];

        foreach ($schema as $field) {
            $handle = $field['handle'] ?? null;
            $required = $field['required'] ?? false;

            if ($required && $handle && (! array_key_exists($handle, $config) || $config[$handle] === '' || $config[$handle] === null)) {
                $issues[] = $this->error(
                    'missing_required_config',
                    "Node '{$node->node_key}' is missing required field '{$handle}'.",
                    $node->node_key,
                );
            }
        }

        return $issues;
    }

    /**
     * Topological cycle check using DFS coloring.
     *
     * @param  array<int, string>  $nodeKeys
     * @param  array<int, \Goldnead\StatamicAutomations\Models\AutomationEdge>  $edges
     */
    protected function hasCycle(array $nodeKeys, array $edges): bool
    {
        $adjacency = [];
        foreach ($nodeKeys as $key) {
            $adjacency[$key] = [];
        }
        foreach ($edges as $edge) {
            if (isset($adjacency[$edge->from_node_key])) {
                $adjacency[$edge->from_node_key][] = $edge->to_node_key;
            }
        }

        $WHITE = 0;
        $GRAY = 1;
        $BLACK = 2;
        $color = array_fill_keys($nodeKeys, $WHITE);

        $visit = function (string $node) use (&$visit, &$color, $adjacency, $GRAY, $BLACK): bool {
            $color[$node] = $GRAY;
            foreach ($adjacency[$node] ?? [] as $neighbor) {
                if (! isset($color[$neighbor])) {
                    continue;
                }
                if ($color[$neighbor] === $GRAY) {
                    return true;
                }
                if ($color[$neighbor] === 0 && $visit($neighbor)) {
                    return true;
                }
            }
            $color[$node] = $BLACK;

            return false;
        };

        foreach ($nodeKeys as $key) {
            if ($color[$key] === $WHITE && $visit($key)) {
                return true;
            }
        }

        return false;
    }

    protected function error(string $code, string $message, ?string $nodeKey = null): array
    {
        $issue = ['level' => 'error', 'code' => $code, 'message' => $message];

        if ($nodeKey !== null) {
            $issue['node_key'] = $nodeKey;
        }

        return $issue;
    }
}
