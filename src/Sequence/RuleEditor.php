<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Goldnead\StatamicAutomations\Support\RestartPolicy;
use RuntimeException;

/**
 * Write one rule back from its row.
 *
 * The row reads "when `<trigger>`, send `<mail>` to `<recipient>`", and this is
 * the only place that sentence is written back. Four things can change from it:
 * the recipient, the template, whether the automation is on, and whether the
 * trigger runs in the request or in the queue. Everything else about the graph
 * belongs on the canvas.
 *
 * **No creating.** The view edits automations that already exist, the same way
 * the mail list does. Making an automation out of a row is a different cut: it
 * would have to choose a trigger, a node type and a handle, and a row is the
 * wrong surface to make three decisions on at once.
 *
 * **{@see RuleShape} is the authority, not the caller.** Every write goes
 * through the same refusal, so a second entry point later cannot route around
 * it — and the refusal carries the shape's own reasons, because an editor told
 * *why* the row is locked can decide whether to simplify the flow or go to the
 * canvas, and one told nothing files a bug.
 *
 * **A field is written only where the node declares it.** The recipient goes
 * into `to` and the template into `template`, but only if the mail node's own
 * schema has that field. A node that takes its recipients from a mailing list
 * has no `to`, and writing one anyway would leave a key in its config that
 * nothing reads — an edit that looks applied and does nothing. This is the same
 * boundary {@see MailSteps} keeps: the sequence layer knows "a node that says
 * it sends a mail", never what a campaign is.
 */
class RuleEditor
{
    /** The mail node field a rule's recipient is written to, where the node has one. */
    public const RECIPIENT_FIELD = 'to';

    /** The mail node field a rule's template is written to, where the node has one. */
    public const TEMPLATE_FIELD = 'template';

    public function __construct(
        protected RuleShape $shape,
        protected NodeRegistry $registry,
    ) {}

    /**
     * Apply the row's edits.
     *
     * Only the keys present in the payload are touched. A row sends what the
     * editor changed; a payload that carried every field would make a toggle
     * on one row overwrite a template somebody else had just picked.
     *
     * @param  array{recipient?: mixed, template?: mixed, enabled?: mixed, dispatch_mode?: mixed}  $payload
     */
    public function update(Automation $automation, array $payload): Automation
    {
        $automation->loadMissing(['nodes', 'edges']);

        $shape = $this->shape->evaluate($automation);

        if (! $shape['editable']) {
            throw new RuntimeException(
                'This automation is not a rule, so it cannot be edited as one: '
                .implode(' ', $shape['reasons'])
                .' Edit it on the canvas instead.'
            );
        }

        $trigger = $automation->nodes->firstWhere('node_key', $shape['trigger_node_key']);
        $mail = $automation->nodes->firstWhere('node_key', $shape['mail_node_key']);

        if ($trigger === null || $mail === null) {
            // Unreachable while the shape says editable, and stated rather than
            // assumed: a later change to the shape must not turn into a
            // null-write here.
            throw new RuntimeException('This automation has no trigger and mail to edit as a rule.');
        }

        if (array_key_exists('enabled', $payload)) {
            $automation->enabled = (bool) $payload['enabled'];
            $automation->save();
        }

        if (array_key_exists('dispatch_mode', $payload)) {
            $this->writeConfig($trigger, DispatchMode::CONFIG_KEY, $this->dispatchMode($payload['dispatch_mode']));
        }

        if (array_key_exists('recipient', $payload)) {
            $this->writeMailField($mail, self::RECIPIENT_FIELD, $payload['recipient'], 'a recipient');
        }

        if (array_key_exists('template', $payload)) {
            $this->writeMailField($mail, self::TEMPLATE_FIELD, $payload['template'], 'a template');
        }

        return $automation->fresh(['nodes', 'edges']) ?? $automation;
    }

    /**
     * Read a dispatch mode somebody chose in a form.
     *
     * Deliberately stricter than {@see DispatchMode::fromValue}, which turns
     * anything it cannot parse into async. That is right when reading a stored
     * value — an unparseable one must not start running automations inside web
     * requests. It is wrong here: this value was just chosen, and coercing it
     * would show the editor async while they believed they had picked
     * something else.
     */
    protected function dispatchMode(mixed $value): string
    {
        $mode = is_string($value) ? DispatchMode::tryFrom($value) : null;

        if ($mode === null) {
            throw new RuntimeException(
                'Unknown dispatch mode. Use "'.DispatchMode::Async->value.'" or "'.DispatchMode::Sync->value.'".'
            );
        }

        return $mode->value;
    }

    /**
     * Write one field into the mail node, where the node has that field.
     *
     * An empty value clears the field, unless the node declares it required —
     * a rule with no recipient is a mail sent nowhere, and a form that let it
     * through would break the automation from a screen that promises to be the
     * safe one.
     */
    protected function writeMailField(AutomationNode $mail, string $handle, mixed $value, string $what): void
    {
        if (! $this->declaresField($mail->type, $handle)) {
            throw new RuntimeException(
                "The mail node '{$mail->type}' has no '{$handle}' field, so a rule row cannot set {$what} on it. Edit it on the canvas instead."
            );
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            if ($this->fieldIsRequired($mail->type, $handle)) {
                throw new RuntimeException("A rule needs {$what}.");
            }

            $this->writeConfig($mail, $handle, null);

            return;
        }

        $this->writeConfig($mail, $handle, $value);
    }

    /**
     * Set (or, with null, remove) one key of a node's config.
     *
     * Read-modify-write of the whole array rather than a partial patch, because
     * node config is free-formed and carries the reserved `_`-prefixed keys of
     * its neighbours ({@see RestartPolicy}): a write that replaced the config
     * would drop settings this screen never shows.
     */
    protected function writeConfig(AutomationNode $node, string $key, ?string $value): void
    {
        $config = is_array($node->config) ? $node->config : [];

        if ($value === null) {
            unset($config[$key]);
        } else {
            $config[$key] = $value;
        }

        $node->config = $config;
        $node->save();
    }

    protected function declaresField(string $type, string $handle): bool
    {
        return $this->field($type, $handle) !== null;
    }

    protected function fieldIsRequired(string $type, string $handle): bool
    {
        return (bool) ($this->field($type, $handle)['required'] ?? false);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function field(string $type, string $handle): ?array
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
