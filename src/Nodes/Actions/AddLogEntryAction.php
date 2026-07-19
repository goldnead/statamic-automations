<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Log;

class AddLogEntryAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'add_log_entry';
    }

    public static function label(): string
    {
        return 'Add Log Entry';
    }

    public static function description(): ?string
    {
        return 'Writes a token-resolved message to the Laravel log channel. Useful for debugging automations.';
    }

    public static function group(): string
    {
        return 'Utilities';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'level',
                'label' => 'Level',
                'type' => 'select',
                'options' => ['debug', 'info', 'notice', 'warning', 'error'],
                'default' => 'info',
            ],
            [
                'handle' => 'message',
                'label' => 'Message',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
            ],
            [
                'handle' => 'context_keys',
                'label' => 'Include context keys',
                'type' => 'tags',
                'help' => 'Optional list of context keys (dot notation) to include in the log entry.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $level = $config['level'] ?? 'info';
        $message = $config['message'] ?? '';
        $keys = (array) ($config['context_keys'] ?? []);

        $logContext = [];
        foreach ($keys as $key) {
            if (is_string($key)) {
                $logContext[$key] = $context->get($key);
            }
        }

        if ($context->isTestMode()) {
            return ActionResult::success([
                'preview' => ['level' => $level, 'message' => $message, 'context' => $logContext],
                'note' => 'Test mode — log entry not written.',
            ]);
        }

        Log::channel(config('logging.default'))->log($level, $message, $logContext + [
            'automation' => true,
        ]);

        return ActionResult::success([
            'logged' => true,
            'level' => $level,
            'message' => $message,
        ]);
    }
}
