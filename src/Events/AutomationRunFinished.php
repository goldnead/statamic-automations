<?php

namespace Goldnead\StatamicAutomations\Events;

use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Arr;

/**
 * A run went through to the end normally (status success).
 *
 * Fired by RunLogger::finishRun() after the run is saved. It does not fire for
 * failed, cancelled or stopped runs, nor for test runs. Other addons listen to
 * it to learn that a run finished; the payload is generic, not tied to one
 * consumer.
 */
class AutomationRunFinished
{
    use Dispatchable;

    /**
     * Context keys that may hold the address the run is about, in order of preference.
     *
     * @var list<string>
     */
    private const EMAIL_KEYS = [
        'subscriber.email',
        'contact.email',
        'user.email',
        'visit.email',
        'grant.email',
        'email',
    ];

    public function __construct(public readonly AutomationRun $run) {}

    /**
     * The handle of the automation this run belongs to, null if it no longer exists.
     */
    public function handle(): ?string
    {
        return $this->run->resolveAutomation()?->handle;
    }

    /**
     * The first valid address found in the run context, lower-cased, or null.
     */
    public function email(): ?string
    {
        $context = $this->run->context ?? [];

        foreach (self::EMAIL_KEYS as $key) {
            $value = Arr::get($context, $key);

            if (! is_string($value)) {
                continue;
            }

            $email = strtolower(trim($value));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @return array{run_id: mixed, automation: ?string, status: string, email: ?string}
     */
    public function payload(): array
    {
        return [
            'run_id' => $this->run->id,
            'automation' => $this->handle(),
            'status' => $this->run->status,
            'email' => $this->email(),
        ];
    }
}
