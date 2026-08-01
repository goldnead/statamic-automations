<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * Value object returned by every action / node executor.
 *
 * Carries the node's structured output (used by downstream tokens),
 * a status string ("success", "skipped", "stopped", "failed"), an
 * optional error message and an "outputHandle" to indicate which
 * outgoing edge should be followed (default / true / false).
 */
class ActionResult
{
    public function __construct(
        public readonly string $status,
        public readonly array $output = [],
        public readonly ?string $error = null,
        public readonly string $outputHandle = 'default',
        public readonly ?array $waitUntil = null,
    ) {}

    public static function success(array $output = [], string $outputHandle = 'default'): self
    {
        return new self(status: 'success', output: $output, outputHandle: $outputHandle);
    }

    public static function skipped(string $reason = ''): self
    {
        return new self(status: 'skipped', output: ['reason' => $reason]);
    }

    public static function stopped(string $reason = ''): self
    {
        return new self(status: 'stopped', output: ['reason' => $reason]);
    }

    public static function failed(string $error, array $output = []): self
    {
        return new self(status: 'failed', output: $output, error: $error);
    }

    /**
     * A required *data reference* could not be resolved.
     *
     * A data reference is a config value that points at a record produced by
     * the run itself — `{{ lead.id }}`, `{{ contact_id }}`, `{{ opportunity.id }}`.
     * It is categorically different from static configuration (a tag, a task
     * title, a list handle), and the difference decides where an action is
     * allowed to validate it:
     *
     *   - **Static configuration is validated before the test-mode
     *     short-circuit.** A node with no title is a broken node, and a test
     *     run exists to say so.
     *   - **Data references are validated after it.** A test run starts from an
     *     empty context by design, so `{{ lead.id }}` resolves to nothing and
     *     *every* action with a required reference would go red — which says
     *     nothing about the automation, only that no real record happened to be
     *     lying around. The test-mode branch therefore comes first and previews
     *     the reference as empty; this failure is reachable on the live path
     *     only.
     *
     * Failures raised here carry `missing_data_reference` in their output so
     * `tests/Feature/TestModeDataReferenceTest.php` can hold that property for
     * every action, including ones written later.
     *
     * @param  string  $field  schema handle of the reference, e.g. `lead_id`
     * @param  string  $label  human label of the field, e.g. `Lead`
     * @param  string  $token  token that normally fills it, e.g. `{{ lead.id }}`
     */
    public static function missingDataReference(string $field, string $label, string $token): self
    {
        return new self(
            status: 'failed',
            output: ['missing_data_reference' => $field],
            error: "No {$label} to act on: the \"{$label}\" field is empty and {$token} did not resolve in this run.",
        );
    }

    /**
     * Indicate that the run should pause and resume after the given
     * delay (used by Delay Nodes).
     *
     * @param  array{seconds?: int, due_at?: string}  $waitUntil
     */
    public static function wait(array $waitUntil, array $output = []): self
    {
        return new self(status: 'waiting', output: $output, waitUntil: $waitUntil);
    }

    /**
     * Branch result helper.
     */
    public static function branch(bool $matched, array $output = []): self
    {
        return new self(
            status: 'success',
            output: $output,
            outputHandle: $matched ? 'true' : 'false',
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isStopped(): bool
    {
        return $this->status === 'stopped';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }
}
