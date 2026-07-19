<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;
use Statamic\Facades\User;

/**
 * Creates (or updates) a Statamic user by email.
 */
class CreateUserAction implements AutomationAction
{
    use NormalizesKeyValue;

    public static function handle(): string
    {
        return 'create_user';
    }

    public static function label(): string
    {
        return 'Create User';
    }

    public static function description(): ?string
    {
        return 'Creates a Statamic user (or updates an existing one with the same email).';
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
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
            ],
            [
                'handle' => 'name',
                'label' => 'Name',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
            ],
            [
                'handle' => 'roles',
                'label' => 'Roles',
                'type' => 'tags',
                'options_source' => 'roles',
                'required' => false,
                'help' => 'Role handles to assign.',
            ],
            [
                'handle' => 'data',
                'label' => 'Additional data',
                'type' => 'key_value',
                'required' => false,
            ],
        ];
    }

    /**
     * Variables this action exposes downstream, e.g. {{ node.user.id }}.
     * Mirrors the keys returned on the success path of execute().
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'user' => [
                'id' => 'string',
                'email' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $email = $config['email'] ?? null;
        if (empty($email)) {
            return ActionResult::failed('An email is required.');
        }

        $data = $this->normalizeKeyValue($config['data'] ?? []);
        if (! empty($config['name'])) {
            $data['name'] = $config['name'];
        }
        $roles = array_values(array_filter((array) ($config['roles'] ?? [])));

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => compact('email', 'data', 'roles'),
                'note' => 'Test mode — user not created.',
            ]);
        }

        $user = User::findByEmail($email) ?: User::make()->email($email);
        $user->data(array_merge(method_exists($user, 'data') ? $user->data()->all() : [], $data));

        foreach ($roles as $role) {
            if (method_exists($user, 'assignRole')) {
                $user->assignRole($role);
            }
        }

        $user->save();

        return ActionResult::success([
            'user' => ['id' => $user->id(), 'email' => $email],
        ]);
    }
}
