<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A suspended course is open again for a learner.
 *
 * `reason` is `payment_recovered` or `manual`.
 */
class CourseAccessRestoredTrigger extends CourseAccessSuspendedTrigger
{
    public static function handle(): string
    {
        return 'courses.access_restored';
    }

    public static function label(): string
    {
        return 'Course Access Restored';
    }

    public static function description(): ?string
    {
        return 'Triggered when a closed course opens again for a learner.';
    }
}
