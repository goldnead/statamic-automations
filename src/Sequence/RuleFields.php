<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/**
 * Which fields of a mail node a rule row maps to.
 *
 * A rule reads "when X happens, send Y to Z". Two of those three are fields on
 * the mail node — the recipient and the template — and this is the one place
 * that says which. Read side ({@see RuleProjection}) and write side
 * ({@see RuleEditor}) both go through it, so a row can never show one field and
 * write another.
 *
 * **A field exists only where the node declares it.** Both are looked up in the
 * node's own schema rather than assumed: a mail node that takes its recipients
 * from a mailing list has no `to`, and one installed without the email-templates
 * addon has no `template`. Reading a key the node does not have would invent a
 * value; writing one would leave a key in its config that nothing reads. This is
 * the same boundary {@see MailSteps} keeps — the sequence layer knows "a node
 * that says it sends a mail", never what a campaign is.
 */
class RuleFields
{
    /** Where a rule's recipient lives, on nodes that address one. */
    public const RECIPIENT = 'to';

    /** Where a rule's template lives, on nodes that send one. */
    public const TEMPLATE = 'template';

    public function __construct(protected NodeRegistry $registry) {}

    public function declares(string $type, string $handle): bool
    {
        return $this->field($type, $handle) !== null;
    }

    public function isRequired(string $type, string $handle): bool
    {
        return (bool) ($this->field($type, $handle)['required'] ?? false);
    }

    /**
     * The choices the node offers for one field, where it offers any.
     *
     * Read off the node's declared schema so the row's picker is the canvas'
     * picker: a template list assembled here would go stale the moment the node
     * learned a new source for it.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(string $type, string $handle): array
    {
        $options = $this->field($type, $handle)['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        $normalised = [];

        foreach ($options as $key => $option) {
            if (is_array($option) && isset($option['value'])) {
                $normalised[] = [
                    'value' => (string) $option['value'],
                    'label' => (string) ($option['label'] ?? $option['value']),
                ];

                continue;
            }

            // A plain `['welcome' => 'Welcome mail']` map, which the config
            // panel accepts too.
            if (is_scalar($option)) {
                $normalised[] = ['value' => (string) $key, 'label' => (string) $option];
            }
        }

        return $normalised;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $type, string $handle): ?array
    {
        $schema = $this->registry->describe($type)['schema'] ?? [];

        if (! is_array($schema)) {
            return null;
        }

        foreach ($schema as $field) {
            if (is_array($field) && ($field['handle'] ?? null) === $handle) {
                return $field;
            }
        }

        return null;
    }
}
