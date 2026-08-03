<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Insert, reorder and delete mails from the list view.
 *
 * Every method here refuses to run on an automation
 * {@see LinearityRule} has not called linear, and the refusal is the feature.
 * The list is a list of mails; mapping an edit to it back onto a graph is only
 * unambiguous while the graph is a straight line. Where it is not, the list
 * stays readable and the canvas is the editing surface — a locked list is a
 * trip to another screen, where a wrong mapping is a rewritten automation
 * nobody asked for.
 *
 * **How an edit is applied.** The chain is read into steps (see
 * {@see MailListProjection}), the steps are rearranged in memory, and the
 * chain's edges are then written out from scratch in the new order. Rewriting
 * rather than patching is what makes reordering safe: there is exactly one
 * shape a linear chain can have, so a full rewrite cannot leave a stale edge
 * behind, and a partial patch of four edges around a moved node can.
 *
 * **A step carries its own delay.** "Wait two days, then send mail three"
 * moves as one thing, because the gap belongs to the mail after it. Any other
 * node that happens to sit in the same gap (a tag, a CRM write) moves with it
 * too, and the list says so on the row rather than moving it silently.
 */
class ChainEditor
{
    /** Horizontal spacing between nodes when the chain is re-laid-out. */
    protected const SPACING = 250;

    public function __construct(
        protected LinearityRule $rule,
        protected MailListProjection $projection,
        protected MailSteps $mails,
    ) {}

    /**
     * Put the mails in the given order.
     *
     * @param  list<string>  $order  every mail node key, in the wanted order
     */
    public function reorder(Automation $automation, array $order): Automation
    {
        $this->assertEditable($automation);

        $steps = $this->linearSteps($automation);
        $keys = array_map(fn (array $step) => $step['mail']->node_key, $steps);

        $wanted = array_values(array_unique(array_map('strval', $order)));

        if (count($wanted) !== count($keys) || array_diff($wanted, $keys) !== [] || array_diff($keys, $wanted) !== []) {
            // Not a permutation. A reorder that silently dropped or invented a
            // mail would be a delete or an insert wearing the wrong name.
            throw new RuntimeException(
                'The given order must contain exactly the mails this automation already has, once each.'
            );
        }

        $byKey = [];

        foreach ($steps as $step) {
            $byKey[$step['mail']->node_key] = $step;
        }

        $reordered = array_map(fn (string $key) => $byKey[$key], $wanted);

        return $this->rewrite($automation, $reordered, $this->projection->tail($automation));
    }

    /**
     * Delete one mail from the list.
     *
     * The delay nodes that preceded it go too — they were the gap before this
     * mail and mean nothing without it. Anything else in the same gap (a tag,
     * a CRM write) is KEPT and becomes part of the following step, because
     * deleting a mail is not permission to delete the work around it.
     */
    public function remove(Automation $automation, string $mailNodeKey): Automation
    {
        $this->assertEditable($automation);

        $steps = $this->linearSteps($automation);
        $index = null;

        foreach ($steps as $position => $step) {
            if ($step['mail']->node_key === $mailNodeKey) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            throw new RuntimeException("No mail with the key '{$mailNodeKey}' in this automation.");
        }

        $removed = $steps[$index];

        $survivors = array_values(array_filter(
            $removed['prefix'],
            fn (AutomationNode $node) => ! $this->isDelay($node),
        ));

        // Hand the survivors to the next step, ahead of its own prefix, so
        // their position relative to everything else is unchanged.
        if (isset($steps[$index + 1])) {
            $steps[$index + 1]['prefix'] = [...$survivors, ...$steps[$index + 1]['prefix']];
            $tail = $this->projection->tail($automation);
        } else {
            // There is no next step; they belong to the tail instead.
            $tail = [...$survivors, ...$this->projection->tail($automation)];
        }

        $doomed = [$removed['mail'], ...array_filter($removed['prefix'], fn (AutomationNode $n) => $this->isDelay($n))];

        unset($steps[$index]);
        $steps = array_values($steps);

        $automation = $this->rewrite($automation, $steps, $tail);

        AutomationNode::query()
            ->where('automation_id', $automation->id)
            ->whereIn('node_key', array_map(fn (AutomationNode $node) => $node->node_key, $doomed))
            ->delete();

        return $automation->fresh(['nodes', 'edges']) ?? $automation;
    }

    /**
     * Add a mail to the list, with the gap that precedes it.
     *
     * @param  array{type: string, config?: array<string, mixed>, label?: string|null, after?: string|null, delay?: array{amount?: int|float|string, unit?: string}|null}  $payload
     */
    public function insert(Automation $automation, array $payload): Automation
    {
        $this->assertEditable($automation);

        $type = (string) ($payload['type'] ?? '');

        if ($type === '') {
            throw new RuntimeException('A mail needs a node type.');
        }

        $steps = $this->linearSteps($automation);
        $after = $payload['after'] ?? null;

        $position = 0;

        if ($after !== null && $after !== '') {
            $position = null;

            foreach ($steps as $index => $step) {
                if ($step['mail']->node_key === $after) {
                    $position = $index + 1;

                    break;
                }
            }

            if ($position === null) {
                throw new RuntimeException("No mail with the key '{$after}' to insert after.");
            }
        }

        $prefix = [];
        $delay = $payload['delay'] ?? null;

        if (is_array($delay) && (int) ($delay['amount'] ?? 0) > 0) {
            $prefix[] = $automation->nodes()->create([
                'node_key' => 'delay_'.Str::lower(Str::random(6)),
                'type' => DelayNode::handle(),
                'position_x' => 0,
                'position_y' => 0,
                'config' => [
                    'amount' => (int) $delay['amount'],
                    'unit' => in_array($delay['unit'] ?? 'days', ['minutes', 'hours', 'days'], true)
                        ? (string) $delay['unit']
                        : 'days',
                ],
            ]);
        }

        $mail = $automation->nodes()->create([
            'node_key' => 'mail_'.Str::lower(Str::random(6)),
            'type' => $type,
            'label' => $payload['label'] ?? null,
            'position_x' => 0,
            'position_y' => 0,
            'config' => is_array($payload['config'] ?? null) ? $payload['config'] : [],
        ]);

        $automation->load(['nodes', 'edges']);

        $step = [
            'mail' => $mail,
            'prefix' => $prefix,
            'extras' => [],
            'delay' => $this->projection->delayOf($prefix),
            'conditional' => false,
            'condition' => null,
        ];

        array_splice($steps, $position, 0, [$step]);

        return $this->rewrite($automation, $steps, $this->projection->tail($automation));
    }

    /**
     * Write the chain out from scratch: trigger → every step in order → tail.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<AutomationNode>  $tail
     */
    protected function rewrite(Automation $automation, array $steps, array $tail): Automation
    {
        $trigger = $automation->nodes->first(fn (AutomationNode $node) => $this->rule->isTrigger($node));

        if ($trigger === null) {
            throw new RuntimeException('The automation has no trigger to build the chain from.');
        }

        $ordered = [];

        foreach ($steps as $step) {
            foreach ($step['prefix'] as $node) {
                $ordered[] = $node;
            }

            $ordered[] = $step['mail'];
        }

        foreach ($tail as $node) {
            $ordered[] = $node;
        }

        $automation->edges()->delete();

        $previous = $trigger;
        $x = (int) $trigger->position_x;
        $y = (int) $trigger->position_y;

        foreach ($ordered as $node) {
            $automation->edges()->create([
                'from_node_key' => $previous->node_key,
                'to_node_key' => $node->node_key,
                'from_output' => 'default',
            ]);

            // Re-laid out along one row. Without this the canvas would show
            // the old positions and a reorder would look like nothing had
            // happened until the page was reloaded and auto-layout ran.
            $x += self::SPACING;
            $node->forceFill(['position_x' => $x, 'position_y' => $y])->save();

            $previous = $node;
        }

        return $automation->fresh(['nodes', 'edges']) ?? $automation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function linearSteps(Automation $automation): array
    {
        $automation->loadMissing(['nodes', 'edges']);

        return $this->projection->steps($automation);
    }

    protected function assertEditable(Automation $automation): void
    {
        $automation->loadMissing(['nodes', 'edges']);

        $verdict = $this->rule->evaluate($automation);

        if (! $verdict['linear']) {
            throw new RuntimeException(
                'This automation is not a straight line, so its mail list cannot be edited: '
                .implode(' ', $verdict['reasons'])
                .' Edit it on the canvas instead.'
            );
        }
    }

    protected function isDelay(AutomationNode $node): bool
    {
        return in_array($node->type, [DelayNode::handle(), 'wait_until'], true);
    }
}
