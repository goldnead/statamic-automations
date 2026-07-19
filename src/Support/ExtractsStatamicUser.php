<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * Shared helper to flatten a Statamic user (from an event) into a plain
 * array for the automation context, plus a role-scope filter. Mirrors the
 * private extraction logic in UserRegisteredTrigger (kept separate there so
 * that trigger's behavior is untouched) for reuse by user_saved/user_deleted.
 */
trait ExtractsStatamicUser
{
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

    protected function userMatchesRole(object|array $event, array $config): bool
    {
        $expectedRole = $config['role'] ?? null;
        if (empty($expectedRole)) {
            return true;
        }

        return in_array($expectedRole, $this->extractUserRoleHandles($event), true);
    }

    /**
     * @return array<int, string>
     */
    protected function extractUserRoleHandles(object|array $event): array
    {
        if (is_array($event)) {
            return array_values(array_filter((array) ($event['user']['roles'] ?? [])));
        }

        $user = $event->user ?? null;
        if (! is_object($user) || ! method_exists($user, 'roles')) {
            return [];
        }

        return collect($user->roles())
            ->map(fn ($role) => is_object($role) && method_exists($role, 'handle') ? $role->handle() : (string) $role)
            ->values()
            ->all();
    }
}
