<?php

namespace Goldnead\StatamicAutomations\Sequence;

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
     * What to show on the row: a human line, and the thing it points at.
     *
     * Falls back to the node's own label and then to the registered node label,
     * so a node that opts in without describing itself still produces a row a
     * reader can identify rather than a blank one.
     *
     * @return array{label: string, reference: string|null}
     */
    public function summarise(AutomationNode $node): array
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
