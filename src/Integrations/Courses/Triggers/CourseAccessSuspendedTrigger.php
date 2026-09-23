<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A course was shut for a learner, whatever their entitlement says.
 *
 * `reason` is `payment_failed` or `manual`. The mail that belongs here tells
 * the learner why the course is closed and how to open it again.
 */
class CourseAccessSuspendedTrigger extends CourseTrigger
{
    public static function handle(): string
    {
        return 'courses.access_suspended';
    }

    public static function label(): string
    {
        return 'Course Access Suspended';
    }

    public static function description(): ?string
    {
        return 'Triggered when a course is closed for a learner, after a failed payment or by hand.';
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
