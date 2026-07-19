<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Statamic\Facades\User;

/**
 * Adds or removes an existing Statamic user from a user group.
 *
 * Side effects are gated behind `persist_statamic_changes` so test runs
 * never write.
 */
class AddUserToGroupAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'add_user_to_group';
    }

    public static function label(): string
    {
        return 'Add User to Group';
    }

    public static function description(): ?string
    {
        return 'Adds or removes a user from a user group, identified by its id.';
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
                'handle' => 'group',
                'label' => 'Group',
                'type' => 'select',
                'options_source' => 'statamic.groups',
                'required' => true,
            ],
            [
                'handle' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'options' => [
                    ['value' => 'add', 'label' => 'Add to group'],
                    ['value' => 'remove', 'label' => 'Remove from group'],
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
                'group' => 'string',
                'mode' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $id = $config['user_id'] ?? null;
        $group = $config['group'] ?? null;
        $mode = ($config['mode'] ?? 'add') === 'remove' ? 'remove' : 'add';

        if (empty($id)) {
            return ActionResult::failed('A user id is required.');
        }
        if (empty($group)) {
            return ActionResult::failed('A group is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => ['user_id' => $id, 'group' => $group, 'mode' => $mode],
                'note' => 'Test mode — user group unchanged.',
            ]);
        }

        $user = User::find($id);
        if ($user === null) {
            return ActionResult::failed("User '{$id}' not found.");
        }

        if ($mode === 'add' && method_exists($user, 'addToGroup')) {
            $user->addToGroup($group);
        } elseif ($mode === 'remove' && method_exists($user, 'removeFromGroup')) {
            $user->removeFromGroup($group);
        }

        $user->save();

        return ActionResult::success([
            'user' => ['id' => (string) $user->id(), 'group' => (string) $group, 'mode' => $mode],
        ]);
    }
}
