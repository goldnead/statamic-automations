<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A lesson went from not completed to completed, by hand or by watching.
 *
 * Once per transition, not on every save. `source` says which: `manual`,
 * `auto` (watched far enough), `assessment` (a passed quiz) and so on.
 */
class LessonCompletedTrigger extends CourseTrigger
{
    protected static bool $hasLesson = true;

    public static function handle(): string
    {
        return 'courses.lesson_completed';
    }

    public static function label(): string
    {
        return 'Lesson Completed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a learner completes a lesson, by hand, by watching it or by passing its quiz.';
    }

    public static function outputSchema(): array
    {
        return [
            'user' => self::userOutputSchema(),
            'course' => self::courseOutputSchema(),
            'lesson' => self::lessonOutputSchema(),
            'source' => 'string',
        ];
    }

    protected function context(object|array $event): array
    {
        return [
            'user' => $this->userOf($this->userIdOf($event)),
            'course' => $this->courseOf($event),
            'lesson' => $this->lessonOf($event),
            'source' => $this->stringOf($this->read($event, 'source')),
        ];
    }
}
