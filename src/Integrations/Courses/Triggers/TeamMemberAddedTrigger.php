<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A buyer put somebody on their team for a course.
 *
 * The run is about the member, not the buyer: the member may have no account
 * yet, and `member.email` is who to invite. It is copied to `email` at the top
 * level so it becomes the subject of the run. The buyer is under `owner`, and
 * deliberately not under `user`, which would make the buyer the subject.
 * `product` is the purchase the seat belongs to: the course's product or a
 * bundle.
 */
class TeamMemberAddedTrigger extends CourseTrigger
{
    /** The member is the subject, by address; the buyer need not be found. */
    protected static bool $needsLearner = false;

    public static function handle(): string
    {
        return 'courses.team_member_added';
    }

    public static function label(): string
    {
        return 'Team Member Added';
    }

    public static function description(): ?string
    {
        return 'Triggered when a buyer gives a team seat of a course to somebody. The run is about the new member.';
    }

    public static function outputSchema(): array
    {
        return [
            'owner' => self::userOutputSchema(),
            'course' => self::courseOutputSchema(),
            'member' => ['email' => 'string'],
            'product' => 'string',
            'email' => 'string',
        ];
    }

    protected function context(object|array $event): array
    {
        $email = $this->stringOf($this->read($event, 'email'));

        return [
            'owner' => $this->userOf($this->stringOf($this->read($event, 'ownerId'))),
            'course' => $this->courseOf($event),
            'member' => ['email' => $email],
            'product' => $this->stringOf($this->read($event, 'product')),
            'email' => $email,
        ];
    }
}
