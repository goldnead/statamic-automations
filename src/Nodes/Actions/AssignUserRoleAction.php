<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Statamic\Facades\User;

/**
 * Adds or removes a role on an existing Statamic user.
 *
 * Side effects are gated behind `persist_statamic_changes` so test runs
 * never write.
 */
class AssignUserRoleAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'assign_user_role';
    }

    public static function label(): string
    {
        return 'Assign User Role';
    }

    public static function description(): ?string
    {
        return 'Adds or removes a role on a user, identified by its id.';
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
                'handle' => 'user_id',
                'label' => 'User',
                'type' => 'select',
                'options_source' => 'statamic.users',
                'required' => true,
                'tokenable' => true,
                'help' => 'Pick a user, or use a token, e.g. {{ user.id }}.',
            ],
            [
                'handle' => 'role',
                'label' => 'Role',
                'type' => 'select',
                'options_source' => 'statamic.roles',
                'required' => true,
            ],
            [
                'handle' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'options' => [
                    ['value' => 'add', 'label' => 'Add role'],
                    ['value' => 'remove', 'label' => 'Remove role'],
                ],
                'default' => 'add',
                'required' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'user' => [
                'id' => 'string',
                'role' => 'string',
                'mode' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $id = $config['user_id'] ?? null;
        $role = $config['role'] ?? null;
        $mode = ($config['mode'] ?? 'add') === 'remove' ? 'remove' : 'add';

        if (empty($id)) {
            return ActionResult::failed('A user id is required.');
        }
        if (empty($role)) {
            return ActionResult::failed('A role is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => ['user_id' => $id, 'role' => $role, 'mode' => $mode],
                'note' => 'Test mode — user role unchanged.',
            ]);
        }

        $user = User::find($id);
        if ($user === null) {
            return ActionResult::failed("User '{$id}' not found.");
        }

        if ($mode === 'add' && method_exists($user, 'assignRole')) {
            $user->assignRole($role);
        } elseif ($mode === 'remove' && method_exists($user, 'removeRole')) {
            $user->removeRole($role);
        }

        $user->save();

        return ActionResult::success([
            'user' => ['id' => (string) $user->id(), 'role' => (string) $role, 'mode' => $mode],
        ]);
    }
}
