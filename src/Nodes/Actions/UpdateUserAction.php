<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;
use Statamic\Facades\User;

/**
 * Updates field data on an existing Statamic user, identified by id
 * (token-resolved).
 *
 * Side effects are gated behind `persist_statamic_changes` so test runs
 * never write.
 */
class UpdateUserAction implements AutomationAction
{
    use NormalizesKeyValue;

    public static function handle(): string
    {
        return 'update_user';
    }

    public static function label(): string
    {
        return 'Update User';
    }

    public static function description(): ?string
    {
        return 'Merges field data into an existing user, identified by its id.';
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
                'handle' => 'data',
                'label' => 'Field data',
                'type' => 'key_value',
                'required' => true,
                'help' => 'Field handle → value. Values may contain tokens.',
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
                'email' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $id = $config['user_id'] ?? null;
        if (empty($id)) {
            return ActionResult::failed('A user id is required.');
        }

        $data = $this->normalizeKeyValue($config['data'] ?? []);

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => ['user_id' => $id, 'data' => $data],
                'note' => 'Test mode — user not updated.',
            ]);
        }

        $user = User::find($id);
        if ($user === null) {
            return ActionResult::failed("User '{$id}' not found.");
        }

        if (method_exists($user, 'merge')) {
            $user->merge($data);
        } else {
            foreach ($data as $key => $value) {
                $user->set($key, $value);
            }
        }

        $user->save();

        return ActionResult::success([
            'user' => [
                'id' => (string) $user->id(),
                'email' => method_exists($user, 'email') ? (string) $user->email() : null,
            ],
        ]);
    }
}
