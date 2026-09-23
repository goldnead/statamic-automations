<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A learner submitted the quiz a lesson embeds and did not pass.
 *
 * Every failed attempt fires. The lesson stays open for another try, so a
 * flow here is encouragement or an offer of help, not a lock.
 */
class QuizFailedTrigger extends QuizPassedTrigger
{
    public static function handle(): string
    {
        return 'courses.quiz_failed';
    }

    public static function label(): string
    {
        return 'Quiz Failed';
    }

    public static function description(): ?string
    {
        return 'Triggered on every attempt at a lesson quiz that did not pass.';
    }
}
