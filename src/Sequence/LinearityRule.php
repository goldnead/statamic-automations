<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Logic\BranchNode;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode;
use Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/**
 * Is this automation a straight line?
 *
 * ## The rule, in words
 *
 * An automation is **linear enough to edit from the list** when all seven of
 * these hold:
 *
 *   1. It has exactly one trigger node.
 *   2. No node has more than one outgoing edge.
 *   3. No node has more than one incoming edge.
 *   4. Every edge leaves its node through the `default` output.
 *   5. No node is a Branch, Switch, Loop or Parallel node.
 *   6. Every node in the automation is reachable from the trigger by
 *      following outgoing edges — there are no orphaned islands.
 *   7. The chain contains no cycle.
 *
 * ## What the rule is for, and what it is not for
 *
 * **It governs editing only. Displaying is always possible.** The list is a
 * list of the mails an automation sends, not a picture of the automation. A
 * branched flow still has a knowable set of mails and knowable gaps between
 * them, so it is still shown; it is merely incomplete as a structural picture,
 * which is why conditional mails are labelled as conditional. Refusing to show
 * anything until a flow is linear would hide the readable part of every flow
 * that grew a single "if they opened it" branch.
 *
 * **Editing is the part that needs the rule**, because inserting, reordering
 * and deleting from a list only work if the list can be mapped back onto the
 * graph unambiguously. Where it cannot, the list stays readable and the flow
 * editor is the editing surface. Erring towards "locked when it need not have
 * been" is the right direction: the cost is a trip to the canvas, where the
 * other direction's cost is a rewritten graph that no longer does what it did.
 *
 * ## Why rules 4 and 5 are both there
 *
 * Rule 4 already excludes a fully-wired Branch, whose second edge leaves
 * through `true` or `false`. Rule 5 excludes a Branch with only one output
 * wired, which passes 2, 3 and 4 while still being a branch: its other output
 * can be connected at any moment from the canvas, and a list that had rewritten
 * the graph in the meantime would have moved a node the branch was about to
 * point at. A node whose *purpose* is to fork is not linear even on a day when
 * it happens to have one edge.
 */
class LinearityRule
{
    /**
     * Node types that fork by nature, whatever their edges look like today.
     *
     * `filter` and `stop` are deliberately absent: both have exactly one
     * output and end the flow when they do not pass, which narrows who
     * continues without changing the order of anything. They make the mails
     * after them conditional — which the projection labels — not ambiguous.
     *
     * @return list<string>
     */
    public static function branchingTypes(): array
    {
        return [
            BranchNode::handle(),
            SwitchNode::handle(),
            LoopNode::handle(),
            ParallelNode::handle(),
        ];
    }

    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return array{linear: bool, reasons: list<string>, trigger: string|null}
     */
    public function evaluate(Automation $automation): array
    {
        $nodes = $automation->nodes;
        $edges = $automation->edges;

        $reasons = [];

        $triggers = $nodes->filter(fn (AutomationNode $node) => $this->isTrigger($node))->values();

        if ($triggers->count() === 0) {
            $reasons[] = 'The automation has no trigger node.';
        } elseif ($triggers->count() > 1) {
            $reasons[] = 'The automation has '.$triggers->count().' trigger nodes; a list can only be edited when there is exactly one.';
        }

        $outgoing = [];
        $incoming = [];

        foreach ($edges as $edge) {
            /** @var AutomationEdge $edge */
            $outgoing[$edge->from_node_key][] = $edge;
            $incoming[$edge->to_node_key][] = $edge;

            if ($edge->from_output !== 'default') {
                $reasons[] = "Node '{$edge->from_node_key}' has an edge on the '{$edge->from_output}' output, so the flow forks.";
            }
        }

        foreach ($outgoing as $nodeKey => $nodeEdges) {
            if (count($nodeEdges) > 1) {
                $reasons[] = "Node '{$nodeKey}' has ".count($nodeEdges).' outgoing edges.';
            }
        }

        foreach ($incoming as $nodeKey => $nodeEdges) {
            if (count($nodeEdges) > 1) {
                $reasons[] = "Node '{$nodeKey}' has ".count($nodeEdges).' incoming edges, so more than one path leads to it.';
            }
        }

        $branching = static::branchingTypes();

        foreach ($nodes as $node) {
            if (in_array($node->type, $branching, true)) {
                $reasons[] = "Node '{$node->node_key}' is a ".$node->type.' node, which forks the flow by nature.';
            }
        }

        $trigger = $triggers->first();

        if ($trigger !== null) {
            $walk = $this->walk($automation, $trigger);

            if ($walk['cycle']) {
                $reasons[] = 'The chain loops back on itself.';
            }

            $unreachable = $nodes
                ->reject(fn (AutomationNode $node) => in_array($node->node_key, $walk['visited'], true))
                ->pluck('node_key')
                ->all();

            if ($unreachable !== []) {
                $reasons[] = 'These nodes cannot be reached from the trigger: '.implode(', ', $unreachable).'.';
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'linear' => $reasons === [],
            'reasons' => $reasons,
            'trigger' => $trigger?->node_key,
        ];
    }

    /**
     * Follow `default` edges from a node and report which keys were seen.
     *
     * Stops on the first repeat, which is both the cycle detection and the
     * safety net: this runs against whatever is in the database, including a
     * graph an import wrote badly.
     *
     * @return array{visited: list<string>, cycle: bool}
     */
    public function walk(Automation $automation, AutomationNode $from): array
    {
        $edges = $automation->edges;
        $nodes = $automation->nodes->keyBy('node_key');

        $visited = [];
        $current = $from;

        while ($current !== null) {
            if (in_array($current->node_key, $visited, true)) {
                return ['visited' => $visited, 'cycle' => true];
            }

            $visited[] = $current->node_key;

            $edge = $edges->first(
                fn (AutomationEdge $candidate) => $candidate->from_node_key === $current->node_key
                    && $candidate->from_output === 'default',
            );

            $current = $edge ? $nodes->get($edge->to_node_key) : null;
        }

        return ['visited' => $visited, 'cycle' => false];
    }

    public function isTrigger(AutomationNode $node): bool
    {
        return $this->registry->kind($node->type) === 'trigger';
    }
}
