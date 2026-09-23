<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * The drip clock stopped for a learner.
 *
 * `reason` is `payment_failed` when the payments bridge paused it and `manual`
 * otherwise. No new lessons open until it resumes.
 */
class DripPausedTrigger extends CourseTrigger
{
    public static function handle(): string
    {
        return 'courses.drip_paused';
    }

    public static function label(): string
    {
        return 'Drip Paused';
    }

    public static function description(): ?string
    {
        return 'Triggered when new lessons stop opening for a learner, for example after a failed payment.';
    }

    public static function outputSchema(): array
    {
        return [
            'user' => self::userOutputSchema(),
            'course' => self::courseOutputSchema(),
            'reason' => 'string',
        ];
    }

    protected function context(object|array $event): array
    {
        return [
            'user' => $this->userOf($this->userIdOf($event)),
            'course' => $this->courseOf($event),
            'reason' => $this->stringOf($this->read($event, 'reason')),
        ];
    }
}
