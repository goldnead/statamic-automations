<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * The drip clock runs again.
 *
 * `paused_seconds` is how long this pause lasted; relative release dates move
 * by that much. `reason` is `payment_recovered` or `manual`.
 */
class DripResumedTrigger extends CourseTrigger
{
    public static function handle(): string
    {
        return 'courses.drip_resumed';
    }

    public static function label(): string
    {
        return 'Drip Resumed';
    }

    public static function description(): ?string
    {
        return 'Triggered when new lessons open again for a learner after a pause.';
    }

    public static function outputSchema(): array
    {
        return [
            'user' => self::userOutputSchema(),
            'course' => self::courseOutputSchema(),
            'reason' => 'string',
            'paused_seconds' => 'integer',
        ];
    }

    protected function context(object|array $event): array
    {
        return [
            'user' => $this->userOf($this->userIdOf($event)),
            'course' => $this->courseOf($event),
            'reason' => $this->stringOf($this->read($event, 'reason')),
            'paused_seconds' => $this->intOf($this->read($event, 'pausedSeconds')),
        ];
    }
}
