<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/**
 * Which nodes count as "a mail" for the list view.
 *
 * This is the one place the list could have learned what a newsletter is, and
 * it deliberately does not. A node declares itself:
 *
 *     public static function mailStep(): bool { return true; }
 *     public static function mailSummary(array $config): array
 *     {
 *         return ['label' => $config['subject'] ?? '', 'reference' => $config['campaign'] ?? null];
 *     }
 *
 * Both are optional and both are found with `method_exists`, so no contract
 * changes and no existing node breaks. `goldnead/statamic-marketing` opts its
 * send node in from its own side, which is what keeps this addon domain-neutral:
 * it knows "a node that says it sends a mail", never "a campaign".
 *
 * An install can also name handles in `automations.sequence.mail_nodes` — for a
 * node in an application's own codebase that nobody wants to edit, or to
 * include a webhook that happens to post to a mail provider.
 */
class MailSteps
{
    public function __construct(protected NodeRegistry $registry) {}

    public function isMail(AutomationNode $node): bool
    {
        return $this->isMailHandle($node->type);
    }

    /**
     * The same question, asked of a registered handle instead of a placed node.
     *
     * The list view needs it to offer an "add a mail" choice at all: a node
     * only becomes a mail row once it is on the canvas, so a screen with no
     * mails yet has nothing to read the answer off. Asking the registry
     * directly is the only way to name the candidates without the UI hardcoding
     * a handle — which is the one thing {@see MailSteps} exists to prevent.
     */
    public function isMailHandle(string $handle): bool
    {
        if (in_array($handle, $this->configuredHandles(), true)) {
            return true;
        }

        $class = $this->registry->class($handle);

        return $class !== null
            && method_exists($class, 'mailStep')
            && (bool) $class::mailStep();
    }

    /**
     * What to show on the row: a human line, the thing it points at, and a
     * version of the line that may be quoted inside a sentence.
     *
     * `label` is the stored value, placeholders and all — the column that shows
     * it is showing the mail's own subject, and an editor has to be able to see
     * what it really says. `display_label` is that same line with its
     * placeholders taken out, and it is what belongs in a question like
     * `Delete “…”?`: a subject template reads as a subject in a table cell and
     * as a defect in a sentence.
     *
     * The fallback chain when nothing readable survives runs through the node's
     * own name before it gives up on the node key. That field exists for exactly
     * this: the add form calls it "Name — Optional. Shown on the canvas and in
     * this list, as long as the step has no subject." A mail called
     * "Willkommensmail" whose subject is nothing but `{{ contact.first_name }}`
     * has a perfectly good name; answering `mail_e71qdm` would be throwing it
     * away.
     *
     * @return array{label: string, display_label: string, reference: string|null}
     */
    public function summarise(AutomationNode $node): array
    {
        $summary = $this->summariseNode($node);

        $summary['display_label'] = $this->firstNonEmpty([
            self::withoutPlaceholders($summary['label']),
            self::withoutPlaceholders(is_string($node->label) ? $node->label : ''),
            self::withoutPlaceholders((string) ($summary['reference'] ?? '')),
            $node->node_key,
        ]);

        return $summary;
    }

    /**
     * A label with its Antlers placeholders taken out and the seam closed.
     *
     * Removed, not resolved: a subject is written against the contact a run will
     * eventually have, and the Control Panel has no such contact — there is no
     * `AutomationContext` to hand {@see TokenResolver}, and filling the gap with
     * an invented name would put a sentence on screen that no reader will ever
     * receive.
     *
     * **Every placeholder, not everything from the first one on.** Cutting at the
     * first `{{` looks equivalent and is not: German subjects that open with the
     * first name are ordinary, not an edge case, and cutting there leaves nothing
     * at all. Worse, it makes the short form ambiguous — `Hallo {{ name }} Teil 2`
     * and `Hallo {{ name }}, willkommen` both collapse to `Hallo`, so the
     * confirmation dialog stops telling two mails apart, which is the one job the
     * name has there.
     *
     *     Hallo {{ name }}, willkommen              → Hallo, willkommen
     *     Hi {{ a }} und {{ b }} tschuess           → Hi und tschuess
     *     Hallo {{ name }} Teil 2                   → Hallo Teil 2
     *     {{ contact.first_name }} — dein Platz     → dein Platz
     *     Newsletter {{if foo}}Ja{{/if}} Ende       → Newsletter Ja Ende
     *     Tag {{ x }}: Teil 2                       → Tag: Teil 2
     *     Betreff (für {{ x }})                     → Betreff (für)
     *     Betreff ({{ campaign }})                  → Betreff
     *
     * The Antlers row is a deliberate choice: the tags go, the text between them
     * stays. That is what the reader sees when the condition holds, it needs no
     * knowledge of which tags open blocks, and it never invents words. Guessing
     * that `Ja` belongs to a branch and dropping it would need an Antlers parser
     * to be right and would be silently wrong whenever it is not.
     *
     * **Each placeholder leaves a space behind, not nothing.** That is the whole
     * of "never invents words": removing the tags of
     * `{{if premium}}Premium{{else}}Basis{{/if}}` without a gap fuses them into
     * `PremiumBasis`, a word no reader will ever be sent. With the gap it reads
     * `Premium Basis` — two words that were both really written. The same gap
     * turns `Hallo{{ name }}Welt` into `Hallo Welt` rather than `HalloWelt`, and
     * that is the better of the two for the same reason: `HalloWelt` is a word
     * nobody typed, while the two halves are.
     *
     * Closing marks are kept, only joiners and openers are trimmed off the seam:
     * `„Zitat“ {{ x }}` keeps its closing quote, and `Betreff (für {{ x }})` keeps
     * its bracket instead of ending on a lonely `(`. A full stop or question mark
     * at the end is the author's, and stays.
     *
     * Returns an empty string when nothing readable is left — a label that is
     * only a placeholder has no short form, and the caller falls back. "Readable"
     * means at least one letter or digit: `{{ x }}.` would otherwise leave `.`,
     * which counts as non-empty and would win against the step's own name, and
     * `Delete “.”?` names nothing.
     */
    public static function withoutPlaceholders(string $label): string
    {
        $text = str_contains($label, '{{') ? self::stripPlaceholders($label) : $label;
        $text = trim($text);

        return preg_match('/[\p{L}\p{N}]/u', $text) === 1 ? $text : '';
    }

    /** The removal itself, on a label that really carries a placeholder. */
    private static function stripPlaceholders(string $label): string
    {
        // Every `{{ … }}`, non-greedy so two placeholders are two matches rather
        // than one that swallows the words between them. `s` because a subject
        // pasted from an editor can carry a newline inside the braces. The space
        // it leaves behind is what keeps two neighbouring words apart.
        $text = (string) preg_replace('/\{\{.*?\}\}/su', ' ', $label);

        // An unclosed `{{` never matched above and would otherwise reach the
        // screen with its braces showing — the whole defect this method answers.
        $text = (string) preg_replace('/\{\{.*$/su', ' ', $text);

        // A bracket pair that held nothing but the placeholder is litter, and
        // removing one pair can expose the next: `A (({{ x }})) B`. Repeat until
        // it settles, with a bound so a pathological label cannot spin.
        for ($i = 0; $i < 10; $i++) {
            $shorter = (string) preg_replace('/\(\s*\)|\[\s*\]/u', '', $text);

            if ($shorter === $text) {
                break;
            }

            $text = $shorter;
        }

        // The space that used to sit in front of the placeholder now sits in
        // front of the punctuation that followed it: "Tag : Teil 2".
        $text = (string) preg_replace('/\s+(?=[,;:.!?)\]}])/u', '', $text);

        $text = (string) preg_replace('/\s+/u', ' ', $text);

        // Joiners and openers promise more text that is no longer there.
        // Closers and sentence enders do not, so they stay.
        $text = (string) preg_replace('/[\s,;:\-–—\/|&+·•(\[{„‚«‹]+$/u', '', $text);
        $text = (string) preg_replace('/^[\s,;:\-–—\/|&+·•)\]}“”»›]+/u', '', $text);

        return trim($text);
    }

    /**
     * The stored line and reference, before the display form is derived.
     *
     * Falls back to the node's own label and then to the registered node label,
     * so a node that opts in without describing itself still produces a row a
     * reader can identify rather than a blank one.
     *
     * @return array{label: string, reference: string|null}
     */
    protected function summariseNode(AutomationNode $node): array
    {
        $class = $this->registry->class($node->type);
        $config = is_array($node->config) ? $node->config : [];

        if ($class !== null && method_exists($class, 'mailSummary')) {
            $summary = $class::mailSummary($config);

            if (is_array($summary)) {
                $label = $summary['label'] ?? null;
                $reference = $summary['reference'] ?? null;

                return [
                    'label' => $this->firstNonEmpty([
                        is_scalar($label) ? (string) $label : null,
                        is_scalar($reference) ? (string) $reference : null,
                        $node->label,
                        $node->node_key,
                    ]),
                    'reference' => is_scalar($reference) && (string) $reference !== '' ? (string) $reference : null,
                ];
            }
        }

        $described = $this->registry->describe($node->type);

        return [
            'label' => $this->firstNonEmpty([
                is_string($node->label) ? $node->label : null,
                is_string($described['label'] ?? null) ? $described['label'] : null,
                $node->node_key,
            ]),
            'reference' => null,
        ];
    }

    /**
     * Every registered handle that counts as a mail, plus the ones an install
     * named in config.
     *
     * The same question as {@see isMailHandle}, asked the other way round. It
     * exists so a caller that wants to know *whether any automation is a rule*
     * can ask the database once, with a `whereIn`, instead of loading every
     * automation and its nodes to run the per-node check in PHP.
     *
     * @return list<string>
     */
    public function handles(): array
    {
        $registered = array_values(array_filter(
            array_map(
                fn (array $node) => (string) ($node['handle'] ?? ''),
                $this->registry->all(),
            ),
            fn (string $handle) => $handle !== '' && $this->isMailHandle($handle),
        ));

        return array_values(array_unique([...$this->configuredHandles(), ...$registered]));
    }

    /**
     * @return list<string>
     */
    protected function configuredHandles(): array
    {
        $configured = config('automations.sequence.mail_nodes', []);

        return is_array($configured)
            ? array_values(array_filter(array_map('strval', $configured)))
            : [];
    }

    /**
     * @param  array<int, string|null>  $candidates
     */
    protected function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
