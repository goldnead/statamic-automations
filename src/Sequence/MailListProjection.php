<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/**
 * The mails an automation sends, in order, with the gap before each one.
 *
 * **This is a list of the e-mails, not a picture of the automation.** The
 * distinction decides everything else in this file. A picture of a branched
 * flow either omits the branch (and lies) or reproduces it (and is the canvas
 * again). A list of the mails is neither: it is incomplete as a structural
 * picture and correct as what it claims to be, so it can be shown for every
 * automation, branched or not. A mail that only some readers get is labelled
 * `conditional` and says which fork it hangs off.
 *
 * **The gap belongs to the mail before it, not to the start.** A row saying
 * "2 days after the previous mail" survives being moved; a row saying "day 4"
 * does not, and reordering a list of absolute days silently rewrites every
 * delay below the one that moved. That is the whole reason the list is editable
 * at all, and it is why a step carries the delay nodes that precede it rather
 * than pointing at them.
 *
 * ## Steps
 *
 * A **step** is everything between one mail and the previous one: any nodes
 * that come before it (delays, tags, CRM writes) plus the mail itself. Nodes
 * after the last mail are the **tail** and belong to no step. Moving a step
 * moves its whole prefix, which is what makes "wait two days, tag them, then
 * send mail three" stay true after a reorder.
 */
class MailListProjection
{
    public function __construct(
        protected NodeRegistry $registry,
        protected LinearityRule $rule,
        protected MailSteps $mails,
    ) {}

    /**
     * The whole answer for one automation: the mails, and whether the list may
     * be edited.
     *
     * @return array{
     *     mails: list<array<string, mixed>>,
     *     editable: bool,
     *     reasons: list<string>,
     *     trigger: string|null,
     *     tail: list<string>,
     * }
     */
    public function forAutomation(Automation $automation): array
    {
        $automation->loadMissing(['nodes', 'edges']);

        $verdict = $this->rule->evaluate($automation);
        $steps = $this->steps($automation);

        $mails = [];
        $position = 0;

        foreach ($steps as $step) {
            $node = $step['mail'];
            $summary = $this->mails->summarise($node);

            $mails[] = [
                'position' => $position++,
                'node_key' => $node->node_key,
                'type' => $node->type,
                'label' => $summary['label'],
                // The same line with everything from its first `{{` onwards
                // removed. The table cell shows `label`, because a subject
                // template is what the mail really carries; every sentence that
                // quotes the mail by name shows this one. See
                // {@see MailSteps::withoutPlaceholders}.
                'display_label' => $summary['display_label'],
                'reference' => $summary['reference'],
                'disabled' => (bool) $node->disabled,
                // Always relative to the mail before it. The first mail's gap
                // is measured from the trigger, which is the only thing that
                // precedes it.
                'delay' => $step['delay'],
                'conditional' => $step['conditional'],
                'condition' => $step['condition'],
                // Anything in the step that is neither a delay nor the mail:
                // shown so a reader knows a row is carrying more than it looks
                // like, and so a reorder is not a surprise.
                'also_runs' => array_map(
                    fn (AutomationNode $extra) => ['node_key' => $extra->node_key, 'type' => $extra->type],
                    $step['extras'],
                ),
            ];
        }

        return [
            'mails' => $mails,
            'editable' => $verdict['linear'],
            'reasons' => $verdict['reasons'],
            'trigger' => $verdict['trigger'],
            'tail' => array_map(fn (AutomationNode $node) => $node->node_key, $this->tail($automation)),
        ];
    }

    /**
     * The steps of an automation, in the order the graph runs them.
     *
     * Walks every reachable path rather than only the `default` chain, so a
     * branched automation still yields its mails. Where a path forks the walk
     * follows each output in turn, marking everything beyond a non-default
     * output as conditional — which is the honest label for "some readers get
     * this".
     *
     * @return list<array{
     *     mail: AutomationNode,
     *     prefix: list<AutomationNode>,
     *     extras: list<AutomationNode>,
     *     delay: array{seconds: int, sources: list<string>},
     *     conditional: bool,
     *     condition: string|null,
     * }>
     */
    public function steps(Automation $automation): array
    {
        $trigger = $automation->nodes->first(fn (AutomationNode $node) => $this->rule->isTrigger($node));

        if ($trigger === null) {
            return [];
        }

        $steps = [];
        $this->collect($automation, $trigger, [], null, $steps, []);

        return $steps;
    }

    /**
     * Nodes after the last mail on the default chain.
     *
     * @return list<AutomationNode>
     */
    public function tail(Automation $automation): array
    {
        $chain = $this->defaultChain($automation);
        $lastMailIndex = null;

        foreach ($chain as $index => $node) {
            if ($this->mails->isMail($node)) {
                $lastMailIndex = $index;
            }
        }

        return $lastMailIndex === null
            ? array_values($chain)
            : array_values(array_slice($chain, $lastMailIndex + 1));
    }

    /**
     * The nodes after the trigger, following `default` edges only.
     *
     * The editable view of the graph. Used by the editor, which never runs on
     * a graph the rule has not already called linear.
     *
     * @return list<AutomationNode>
     */
    public function defaultChain(Automation $automation): array
    {
        $trigger = $automation->nodes->first(fn (AutomationNode $node) => $this->rule->isTrigger($node));

        if ($trigger === null) {
            return [];
        }

        $walk = $this->rule->walk($automation, $trigger);
        $byKey = $automation->nodes->keyBy('node_key');

        // Drop the trigger itself: it is where the chain starts, not a step in
        // it, and nothing may ever reorder or delete it from a mail list.
        return array_values(array_filter(array_map(
            fn (string $key) => $byKey->get($key),
            array_slice($walk['visited'], 1),
        )));
    }

    /**
     * Depth-first walk that closes a step on every mail it meets.
     *
     * @param  list<AutomationNode>  $prefix
     * @param  list<array{mail: AutomationNode, prefix: list<AutomationNode>, extras: list<AutomationNode>, delay: array{seconds: int, sources: list<string>}, conditional: bool, condition: string|null}>  $steps
     * @param  list<string>  $seen
     */
    protected function collect(
        Automation $automation,
        AutomationNode $current,
        array $prefix,
        ?string $condition,
        array &$steps,
        array $seen,
    ): void {
        if (in_array($current->node_key, $seen, true)) {
            return; // A cycle. The list stops rather than growing for ever.
        }

        $seen[] = $current->node_key;

        // Sorted by id, which is the order the edges were wired in. Without it
        // the branches of a fork would come back in whatever order the database
        // felt like, and the same automation would produce a differently
        // ordered list on two page loads.
        $outgoing = $automation->edges
            ->filter(fn (AutomationEdge $edge) => $edge->from_node_key === $current->node_key)
            ->sortBy('id')
            ->values();

        $byKey = $automation->nodes->keyBy('node_key');

        foreach ($outgoing as $edge) {
            $next = $byKey->get($edge->to_node_key);

            // A node this path has already been through closes the loop. It is
            // skipped rather than listed a second time: a cycle does not mean
            // the reader gets the mail twice for every lap, it means the graph
            // is not something a list can describe — which is what `editable:
            // false` says next to it.
            if ($next === null || in_array($next->node_key, $seen, true)) {
                continue;
            }

            // A non-`default` output means somebody chose a path here, so
            // everything beyond it reaches only some of the people in the flow.
            $branchCondition = $edge->from_output === 'default'
                ? $condition
                : trim(($current->label ?: $current->type).' → '.$edge->from_output);

            // A filter narrows without forking, and still makes what follows
            // conditional — the one case the edge output cannot tell us about.
            if ($branchCondition === null && $this->narrows($current)) {
                $branchCondition = ($current->label ?: $current->type).' matched';
            }

            if ($this->mails->isMail($next)) {
                $steps[] = [
                    'mail' => $next,
                    'prefix' => $prefix,
                    'extras' => array_values(array_filter(
                        $prefix,
                        fn (AutomationNode $node) => ! $this->isDelay($node),
                    )),
                    'delay' => $this->delayOf($prefix),
                    'conditional' => $branchCondition !== null,
                    'condition' => $branchCondition,
                ];

                $this->collect($automation, $next, [], $branchCondition, $steps, $seen);

                continue;
            }

            $this->collect($automation, $next, [...$prefix, $next], $branchCondition, $steps, $seen);
        }
    }

    /**
     * The waiting time a step's prefix adds up to.
     *
     * `wait_until` contributes no seconds and is named instead: "until the next
     * Tuesday at 9" is a real gap that no number of seconds describes, and
     * printing a made-up number would be worse than printing the rule.
     *
     * @param  list<AutomationNode>  $prefix
     * @return array{seconds: int, sources: list<string>}
     */
    public function delayOf(array $prefix): array
    {
        $seconds = 0;
        $sources = [];

        foreach ($prefix as $node) {
            if ($node->type === DelayNode::handle()) {
                $amount = max(0, (int) data_get($node->config ?? [], 'amount', 0));
                $unit = (string) data_get($node->config ?? [], 'unit', 'minutes');

                $seconds += match ($unit) {
                    'days' => $amount * 86400,
                    'hours' => $amount * 3600,
                    default => $amount * 60,
                };

                $sources[] = $node->node_key;

                continue;
            }

            if ($node->type === WaitUntilNode::handle()) {
                $sources[] = $node->node_key;
            }
        }

        return ['seconds' => $seconds, 'sources' => $sources];
    }

    protected function isDelay(AutomationNode $node): bool
    {
        return in_array($node->type, [DelayNode::handle(), WaitUntilNode::handle()], true);
    }

    /** A node that ends the flow for some people without forking it. */
    protected function narrows(AutomationNode $node): bool
    {
        return in_array($node->type, ['filter', 'throttle'], true);
    }
}
