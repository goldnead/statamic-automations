<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A learner was enrolled in a course for the first time.
 *
 * Once per learner and course, by the call that created the enrollment. The
 * welcome-to-the-course mail belongs here rather than on the payment: a team
 * seat, a bundle and a free course all enrol without a payment of their own.
 */
class LearnerEnrolledTrigger extends CourseTrigger
{
    public static function handle(): string
    {
        return 'courses.learner_enrolled';
    }

    public static function label(): string
    {
        return 'Learner Enrolled';
    }

    public static function description(): ?string
    {
        return 'Triggered once when somebody is enrolled in a course for the first time.';
    }

    public static function outputSchema(): array
    {
        return [
            'user' => self::userOutputSchema(),
            'course' => self::courseOutputSchema(),
        ];
    }

    protected function context(object|array $event): array
    {
        return [
            'user' => $this->userOf($this->userIdOf($event)),
            'course' => $this->courseOf($event),
        ];
    }
}
