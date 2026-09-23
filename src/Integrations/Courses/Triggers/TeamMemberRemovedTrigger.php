<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

/**
 * A buyer took somebody off their team; the seat is free again.
 *
 * Same shape as {@see TeamMemberAddedTrigger}: the run is about the member.
 */
class TeamMemberRemovedTrigger extends TeamMemberAddedTrigger
{
    public static function handle(): string
    {
        return 'courses.team_member_removed';
    }

    public static function label(): string
    {
        return 'Team Member Removed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a buyer takes somebody off a course team. The run is about the removed member.';
    }
}
