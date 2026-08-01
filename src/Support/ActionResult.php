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
