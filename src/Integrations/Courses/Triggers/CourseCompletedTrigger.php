<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * Every lesson of a course is completed, milestones included.
 *
 * Once per learner and course, by the write that completed the last lesson.
 */
class CourseCompletedTrigger extends CourseTrigger
{
    public static function handle(): string
    {
        return 'courses.course_completed';
    }

    public static function label(): string
    {
        return 'Course Completed';
    }

    public static function description(): ?string
    {
        return 'Triggered once when a learner has completed every lesson of a course.';
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
