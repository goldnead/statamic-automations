<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A lesson went from locked to open for a learner, because of a write.
 *
 * A completed lesson, a passed quiz, a payment, a resumed drip. A lesson the
 * calendar opens (drip by days or by date) has no write behind it and does
 * not fire this; the courses addon says so itself.
 */
class LessonUnlockedTrigger extends CourseTrigger
{
    protected static bool $hasLesson = true;

    public static function handle(): string
    {
        return 'courses.lesson_unlocked';
    }

    public static function label(): string
    {
        return 'Lesson Unlocked';
    }

    public static function description(): ?string
    {
        return 'Triggered when a lesson opens for a learner because of something they did or paid. Not for lessons opened by date alone.';
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
