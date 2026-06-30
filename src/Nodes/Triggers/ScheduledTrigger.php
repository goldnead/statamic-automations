<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Time-based trigger. Not event-driven: the `automations:run-scheduled`
 * command (registered on Laravel's scheduler) evaluates due automations
 * and dispatches their runs. matches() therefore always returns true —
 * the command is responsible for the due-check.
 */
class ScheduledTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'scheduled';
    }

    public static function label(): string
    {
        return 'Schedule';
    }

    public static function description(): ?string
    {
        return 'Runs the automation on a fixed schedule (interval or cron).';
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
                'handle' => 'frequency',
                'label' => 'Frequency',
                'type' => 'select',
                'options' => [
                    ['value' => 'hourly', 'label' => 'Hourly'],
                    ['value' => 'daily', 'label' => 'Daily'],
                    ['value' => 'weekly', 'label' => 'Weekly'],
                    ['value' => 'monthly', 'label' => 'Monthly'],
                    ['value' => 'cron', 'label' => 'Custom (cron)'],
                ],
                'default' => 'daily',
                'required' => true,
            ],
            [
                'handle' => 'time',
                'label' => 'Time (HH:MM)',
                'type' => 'text',
                'default' => '09:00',
                'help' => 'For daily/weekly/monthly schedules.',
            ],
            [
                'handle' => 'day',
                'label' => 'Day',
                'type' => 'text',
                'required' => false,
                'help' => 'Weekly: 0–6 (Sun–Sat). Monthly: 1–31.',
            ],
            [
                'handle' => 'cron',
                'label' => 'Cron expression',
                'type' => 'text',
                'required' => false,
                'help' => 'Used when frequency is "Custom (cron)", e.g. */15 * * * *.',
            ],
            [
                'handle' => 'timezone',
                'label' => 'Timezone',
                'type' => 'text',
                'required' => false,
                'help' => 'IANA name, e.g. Europe/Berlin. Defaults to the app timezone.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'scheduled' => ['at' => 'string', 'frequency' => 'string'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $at = is_array($event) ? ($event['at'] ?? null) : null;

        return AutomationContext::make([
            'scheduled' => [
                'at' => $at,
                'frequency' => $config['frequency'] ?? null,
            ],
        ]);
    }
}
