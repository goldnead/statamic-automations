<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Support\ExtractsStatamicUser;

class UserDeletedTrigger implements AutomationTrigger
{
    use ExtractsStatamicUser;

    public static function handle(): string
    {
        return 'user_deleted';
    }

    public static function label(): string
    {
        return 'User Deleted';
    }

    public static function description(): ?string
    {
        return 'Triggered when a user is deleted.';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'role',
                'label' => 'Role',
                'type' => 'select',
                'options_source' => 'roles',
                'required' => false,
                'help' => 'Leave empty to trigger for any role.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'user' => ['id' => 'string', 'email' => 'string', 'name' => 'string', 'data' => 'array'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->userMatchesRole($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['user' => $this->extractUser($event)]);
    }
}
