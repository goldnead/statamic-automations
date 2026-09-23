<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A learner passed the quiz a lesson embeds.
 *
 * Fired after the lesson was completed, so after `courses.lesson_completed`
 * and any unlock it caused.
 */
class QuizPassedTrigger extends CourseTrigger
{
    protected static bool $hasLesson = true;

    public static function handle(): string
    {
        return 'courses.quiz_passed';
    }

    public static function label(): string
    {
        return 'Quiz Passed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a learner passes the quiz in a lesson.';
    }

    public static function outputSchema(): array
    {
        return [
            'user' => self::userOutputSchema(),
            'course' => self::courseOutputSchema(),
            'lesson' => self::lessonOutputSchema(),
            'quiz' => self::quizOutputSchema(),
        ];
    }

    protected function context(object|array $event): array
    {
        return [
            'user' => $this->userOf($this->userIdOf($event)),
            'course' => $this->courseOf($event),
            'lesson' => $this->lessonOf($event),
            'quiz' => $this->quizOf($event),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function quizOutputSchema(): array
    {
        return [
            'assessment' => 'string',
            'score' => 'integer',
            'result_key' => 'string',
            'response_id' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function quizOf(object|array $event): array
    {
        return [
            'assessment' => $this->stringOf($this->read($event, 'assessment')),
            'score' => $this->intOf($this->read($event, 'score')),
            'result_key' => $this->stringOf($this->read($event, 'resultKey')),
            'response_id' => $this->intOf($this->read($event, 'responseId')),
        ];
    }
}
