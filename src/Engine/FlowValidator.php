<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Actions\AiGenerateAction;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Goldnead\StatamicAutomations\Support\NodeOutputs;
use Illuminate\Support\Collection;

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
    public function __construct(protected NodeRegistry $nodes) {}

    /**
     * @return array<int, array{level: string, code: string, message: string, node_key?: string}>
     */
    public function validate(Automation $automation): array
    {
        $issues = [];

        // Use loaded relations when present so flat-file (non-persisted)
        // automations validate the same as database-backed ones.
        $nodes = $automation->relationLoaded('nodes') ? $automation->nodes : $automation->nodes()->get();
        $edges = $automation->relationLoaded('edges') ? $automation->edges : $automation->edges()->get();

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

            $issues = array_merge($issues, $this->validateOutputs($node, $edges));

            // 5) Required config fields.
            $issues = array_merge(
                $issues,
                $this->validateConfig($node, $registryEntry['class']),
            );
        }

        // 6) AI nodes: valid settings, and never inside a synchronous run.
        $issues = array_merge($issues, $this->validateAiNodes($nodes, $edges, $triggers));

        // 7) No cycles.
        if ($this->hasCycle($nodes->pluck('node_key')->all(), $edges->all())) {
            $issues[] = $this->error('cycle_detected', 'Automation contains a cycle.');
        }

        return $issues;
    }

    /**
     * `ai_generate` waits on a model for as long as its timeout, and a long
     * input needs minutes. A sync trigger runs the whole flow inside the
     * request that fired it, so an AI node reachable from one would hold that
     * page load for the model's full latency. That is refused here rather than
     * left to time out in production.
     *
     * @param  Collection<int, AutomationNode>  $nodes
     * @param  Collection<int, AutomationEdge>  $edges
     * @param  Collection<int, AutomationNode>  $triggers
     * @return array<int, array{level: string, code: string, message: string, node_key?: string}>
     */
    protected function validateAiNodes($nodes, $edges, $triggers): array
    {
        $issues = [];
        $ai = $nodes->filter(fn ($n) => $n->type === AiGenerateAction::handle());

        foreach ($ai as $node) {
            foreach (AiGenerateAction::configProblems($node->config ?? []) as $problem) {
                $issue = $this->error($problem['code'], $problem['message'], $node->node_key);
                $issue['field'] = $problem['field'];
                $issues[] = $issue;
            }
        }

        if ($ai->isEmpty()) {
            return $issues;
        }

        // An inline loop runs its body once per item, so an AI node in it is
        // called N times while the job timeout budgets it once. N is only known
        // at run time, so the budget cannot be right: refuse it.
        foreach ($nodes->where('type', 'loop') as $loop) {
            if (($loop->config['mode'] ?? 'inline') === 'automation') {
                continue;
            }

            $body = [];
            foreach ($edges->where('from_node_key', $loop->node_key)->where('from_output', LoopNode::OUTPUT_LOOP) as $edge) {
                $body = array_merge($body, $this->reachableFrom((string) $edge->to_node_key, $edges->all()));
            }

            foreach ($ai as $node) {
                if (in_array($node->node_key, $body, true)) {
                    $issues[] = $this->error(
                        'ai_in_loop',
                        "AI node '{$node->node_key}' is inside the body of loop '{$loop->node_key}' and would run once per item, more often than the job's time budget allows for. Run the loop over a separate automation, or put the AI call outside the loop.",
                        $node->node_key,
                    );
                }
            }
        }

        foreach ($triggers as $trigger) {
            $config = $trigger->config ?? [];
            $mode = DispatchMode::fromValue(
                is_string($config[DispatchMode::CONFIG_KEY] ?? null) ? $config[DispatchMode::CONFIG_KEY] : null
            );

            if ($mode !== DispatchMode::Sync) {
                continue;
            }

            $reachable = $this->reachableFrom($trigger->node_key, $edges->all());

            foreach ($ai as $node) {
                if (in_array($node->node_key, $reachable, true)) {
                    $issues[] = $this->error(
                        'ai_requires_queue',
                        "AI node '{$node->node_key}' is reachable from the synchronous trigger '{$trigger->node_key}'. Set the trigger to run in the background: a model call can take minutes and must not run inside the request.",
                        $node->node_key,
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<int, AutomationEdge>  $edges
     * @return array<int, string>
     */
    protected function reachableFrom(string $start, array $edges): array
    {
        $seen = [$start => true];
        $queue = [$start];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($edges as $edge) {
                if ($edge->from_node_key === $current && ! isset($seen[$edge->to_node_key])) {
                    $seen[$edge->to_node_key] = true;
                    $queue[] = $edge->to_node_key;
                }
            }
        }

        return array_map('strval', array_keys($seen));
    }

    /**
     * Every edge must leave an output handle its source node actually has.
     *
     * Until 1.7.0 this could only be asked of a branch, because the handles
     * a node has were not knowable from here — `outputs()` was declared on
     * three node classes and nowhere read. Now the registry answers for any
     * type, against that node's own config, so a third-party node's declared
     * handles are held to here exactly as the built-ins' are.
     *
     * Two levels, and the split is deliberate. A branch is an **error**, as
     * it has been since the first release: its handles are fixed, the engine
     * cannot route anything else, and 1.5.5 made "Duplicate" stop producing
     * such an edge. Every other mismatch is a **warning**, because the
     * handles can be config-dependent — edit a switch's cases or a parallel's
     * branches and edges wired to the old handles are still stored. Raising
     * those to errors would refuse to enable automations that were enabled
     * yesterday, on a graph the user has not touched.
     *
     * `error` is never reported: it is the handle the runner takes when a
     * node fails under `_on_error: continue`, so every node has it whether
     * or not its spec says so.
     *
     * @param  Collection<int, AutomationEdge>  $edges
     * @return array<int, array{level: string, code: string, message: string, node_key?: string}>
     */
    protected function validateOutputs(
        AutomationNode $node,
        $edges,
    ): array {
        $issues = [];
        $declared = $this->nodes->outputsFor($node->type, $node->config ?? []);
        $isBranch = $node->type === 'branch' || str_ends_with((string) $node->type, '.branch');

        foreach ($edges->where('from_node_key', $node->node_key) as $edge) {
            $output = (string) ($edge->from_output ?: 'default');

            if ($output === NodeOutputs::ERROR_HANDLE) {
                continue;
            }

            if (in_array($output, $declared, true)) {
                continue;
            }

            $issues[] = $isBranch
                ? $this->error(
                    'branch_invalid_output',
                    "Branch node '{$node->node_key}' has invalid output handle '{$edge->from_output}'.",
                    $node->node_key,
                )
                : $this->warning(
                    'edge_unknown_output',
                    "Node '{$node->node_key}' has an edge on output handle '{$output}', which it does not declare ("
                        .($declared === [] ? 'it declares none' : 'declared: '.implode(', ', $declared))
                        .').',
                    $node->node_key,
                );
        }

        return $issues;
    }

    /**
     * @param  class-string  $class
     * @return array<int, array{level: string, code: string, message: string, node_key?: string}>
     */
    protected function validateConfig(AutomationNode $node, string $class): array
    {
        $issues = [];
        $schema = method_exists($class, 'schema') ? $class::schema() : [];
        $config = $node->config ?? [];

        foreach ($schema as $field) {
            $handle = $field['handle'] ?? null;
            $required = $field['required'] ?? false;

            if ($required && $handle && (! array_key_exists($handle, $config) || $config[$handle] === '' || $config[$handle] === null)) {
                // Carry the field handle so the editor can mark the specific
                // invalid field inline (A3), not just the node.
                $issue = $this->error(
                    'missing_required_config',
                    "Node '{$node->node_key}' is missing required field '{$handle}'.",
                    $node->node_key,
                );
                $issue['field'] = $handle;
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Topological cycle check using DFS coloring.
     *
     * @param  array<int, string>  $nodeKeys
     * @param  array<int, AutomationEdge>  $edges
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
        return $this->issue('error', $code, $message, $nodeKey);
    }

    protected function warning(string $code, string $message, ?string $nodeKey = null): array
    {
        return $this->issue('warning', $code, $message, $nodeKey);
    }

    protected function issue(string $level, string $code, string $message, ?string $nodeKey = null): array
    {
        $issue = ['level' => $level, 'code' => $code, 'message' => $message];

        if ($nodeKey !== null) {
            $issue['node_key'] = $nodeKey;
        }

        return $issue;
    }
}
