<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Cache;

/**
 * Throttle / de-duplicate runs by a key within a time window.
 *
 * The first time a given (token-resolved) key is seen the node lets the
 * flow continue and remembers the key for `window_minutes`. A repeat of the
 * same key inside the window stops the flow, so duplicate webhook deliveries
 * or rapid re-saves don't fire the downstream actions twice.
 *
 * In test mode the key is never recorded, so test runs always pass through.
 */
class ThrottleNode implements AutomationNode
{
    public static function handle(): string
    {
        return 'throttle';
    }

    public static function label(): string
    {
        return 'Throttle / Deduplicate';
    }

    public static function description(): ?string
    {
        return 'Stops duplicate runs that share a key within a time window.';
    }

    public static function group(): string
    {
        return 'Logic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'key',
                'label' => 'Dedupe key',
                'type' => 'text',
                'required' => true,
                'help' => 'A token that identifies a unique run, e.g. {{ trigger.entry.id }}.',
            ],
            [
                'handle' => 'window_minutes',
                'label' => 'Window (minutes)',
                'type' => 'integer',
                'default' => 60,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $key = trim((string) ($config['key'] ?? ''));
        if ($key === '') {
            return ActionResult::failed('A dedupe key is required.');
        }

        $window = max(1, (int) ($config['window_minutes'] ?? 60));
        $cacheKey = 'statamic-automations.throttle.' . md5($key);

        if ($context->isTestMode()) {
            return ActionResult::success(['key' => $key, 'duplicate' => false, 'note' => 'Test mode — not recorded.']);
        }

        if (Cache::has($cacheKey)) {
            return ActionResult::stopped("Duplicate within {$window} minute window for key '{$key}'.");
        }

        Cache::put($cacheKey, true, now()->addMinutes($window));

        return ActionResult::success(['key' => $key, 'duplicate' => false, 'window_minutes' => $window]);
    }
}
