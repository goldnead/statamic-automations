<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;

/**
 * Is this automation a rule?
 *
 * A **rule** is the two-node case: a trigger, then one mail, nothing in
 * between. Read as a sentence — "when X happens, send Y to Z" — it fits on one
 * row, and a row is a better editing surface for it than a canvas with two
 * boxes on it.
 *
 * ## The rule, in words
 *
 * An automation is editable as a rule when all four hold:
 *
 *   1. It is linear — every reason {@see LinearityRule} gives applies here too.
 *   2. It has exactly two nodes.
 *   3. Exactly one of them is a mail node ({@see MailSteps::isMail()}).
 *   4. The other is the trigger, and the single edge runs from it to the mail.
 *
 * ## Built on LinearityRule, not beside it
 *
 * Every reason a list cannot be edited is also a reason a rule cannot be:
 * two triggers, a fork, an unreachable island, a cycle. Re-deriving them here
 * would mean two implementations of the same graph rules drifting apart, and
 * two different wordings for the same fact in front of the same editor.
 *
 * ## Displaying is always possible
 *
 * Same split as the mail list: `editable` governs writing only. A refused shape
 * still reports `trigger_node_key` and `mail_node_key` where it can, because
 * the row is shown either way and has to say what the automation does before it
 * says why it cannot be edited here.
 */
class RuleShape
{
    public function __construct(
        protected LinearityRule $linearity,
        protected MailSteps $mails,
    ) {}

    /**
     * @return array{
     *     editable: bool,
     *     reasons: list<string>,
     *     trigger_node_key: string|null,
     *     mail_node_key: string|null,
     * }
     */
    public function evaluate(Automation $automation): array
    {
        $automation->loadMissing(['nodes', 'edges']);

        $linearity = $this->linearity->evaluate($automation);
        $reasons = $linearity['reasons'];

        $nodes = $automation->nodes;
        $mailNodes = $nodes->filter(fn (AutomationNode $node) => $this->mails->isMail($node))->values();
        $triggerKey = $linearity['trigger'];
        $mailKey = $mailNodes->first()?->node_key;

        if ($mailNodes->count() === 0) {
            $reasons[] = 'The automation sends no mail, so there is no rule to read off it.';
        } elseif ($mailNodes->count() > 1) {
            $reasons[] = 'The automation sends '.$mailNodes->count().' mails; a rule is one trigger and one mail. Edit it as a sequence or on the canvas.';
        }

        // Anything that is neither the trigger nor the mail sits between them
        // or after them, and either way the sentence "when X, send Y" would be
        // leaving something out. Naming the types is what lets the editor see
        // at a glance whether the extra step matters.
        $extra = $nodes
            ->reject(fn (AutomationNode $node) => $node->node_key === $triggerKey || $node->node_key === $mailKey)
            ->values();

        if ($extra->isNotEmpty()) {
            $reasons[] = 'The automation has '.$extra->count().' step(s) besides the trigger and the mail ('
                .$extra->map(fn (AutomationNode $node) => $node->type)->implode(', ')
                .'), which a single rule row cannot show.';
        }

        if ($triggerKey !== null && $mailKey !== null && $extra->isEmpty()) {
            $connected = $automation->edges->contains(
                fn ($edge) => $edge->from_node_key === $triggerKey && $edge->to_node_key === $mailKey,
            );

            if (! $connected) {
                $reasons[] = 'The mail is not connected to the trigger.';
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'editable' => $reasons === [],
            'reasons' => $reasons,
            'trigger_node_key' => $triggerKey,
            'mail_node_key' => $mailKey,
        ];
    }
}
