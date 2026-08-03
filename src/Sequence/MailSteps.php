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
        if (in_array($node->type, $this->configuredHandles(), true)) {
            return true;
        }

        $class = $this->registry->class($node->type);

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
