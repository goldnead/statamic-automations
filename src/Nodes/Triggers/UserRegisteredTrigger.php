<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class UserRegisteredTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'user_registered';
    }

    public static function label(): string
    {
        return 'User Registered';
    }

    public static function description(): ?string
    {
        return 'Triggered when a new user registers.';
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
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'user' => ['id' => 'string', 'email' => 'string', 'name' => 'string', 'data' => 'array'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['user' => $this->extractUser($event)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractUser(object|array $event): array
    {
        if (is_array($event)) {
            return $event['user'] ?? [];
        }

        $user = $event->user ?? null;
        if (! is_object($user)) {
            return [];
        }

        return [
            'id' => method_exists($user, 'id') ? $user->id() : null,
            'email' => method_exists($user, 'email') ? $user->email() : null,
            'name' => method_exists($user, 'name') ? $user->name() : null,
            'data' => method_exists($user, 'data')
                ? (is_object($user->data()) && method_exists($user->data(), 'all') ? $user->data()->all() : (array) $user->data())
                : [],
        ];
    }
}
