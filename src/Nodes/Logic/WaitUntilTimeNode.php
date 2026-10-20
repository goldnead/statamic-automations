<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;

/**
 * Waits until a point in time that comes from the run's data, plus or minus an offset.
 *
 * Unlike {@see WaitForAutomationNode}, the run does not come back to this node:
 * once the target instant has passed, the run continues with the NEXT node.
 *
 * ## Reading the time
 *
 * `at` is any text Carbon can parse, usually ISO 8601 from a token such as
 * `{{ booking.scheduled_at }}`. The offset moves the target: a negative amount
 * means earlier, so "24 hours before the appointment" is `at` plus
 * `offset_amount = -24`, `offset_unit = hours`.
 *
 * ## Outcomes
 *
 *  - Target in the future: the run parks until then and continues afterwards.
 *  - Target now or in the past: the run continues at once with `late` = true.
 *  - Missing or unreadable target: the run fails. It never passes through silently.
 *
 * ## Test mode
 *
 * Never pauses. It reports `would_wait_until`. An empty `at` in a test run is
 * not an error (it is a data reference, see {@see ActionResult::missingDataReference()}).
 */
class WaitUntilTimeNode implements AutomationLogicNode
{
    public const UNIT_MINUTES = 'minutes';

    public const UNIT_HOURS = 'hours';

    public const UNIT_DAYS = 'days';

    public static function handle(): string
    {
        return 'wait_until_time';
    }

    public static function label(): string
    {
        return 'Wait Until Time';
    }

    public static function description(): ?string
    {
        return 'Pauses until a point in time from the run, plus or minus an offset, then continues with the next step.';
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
                'handle' => 'at',
                'label' => 'Point in time',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'An ISO 8601 date and time, for example {{ booking.scheduled_at }}.',
            ],
            [
                'handle' => 'offset_amount',
                'label' => 'Offset',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'help' => 'Negative values move the target earlier, for example -24 with hours for one day before.',
            ],
            [
                'handle' => 'offset_unit',
                'label' => 'Offset unit',
                'type' => 'select',
                'required' => false,
                'default' => self::UNIT_HOURS,
                'options' => [
                    ['value' => self::UNIT_MINUTES, 'label' => 'Minutes'],
                    ['value' => self::UNIT_HOURS, 'label' => 'Hours'],
                    ['value' => self::UNIT_DAYS, 'label' => 'Days'],
                ],
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'late' => 'boolean',
            'waited' => 'boolean',
            'due_at' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $at = trim((string) ($config['at'] ?? ''));
        $unit = trim((string) ($config['offset_unit'] ?? '')) ?: self::UNIT_HOURS;
        $offset = $config['offset_amount'] ?? 0;
        $offset = $offset === '' ? 0 : $offset;

        if (! in_array($unit, [self::UNIT_MINUTES, self::UNIT_HOURS, self::UNIT_DAYS], true)) {
            return ActionResult::failed(sprintf('Unknown offset unit "%s": use "minutes", "hours" or "days".', $unit));
        }

        if (! is_numeric($offset)) {
            return ActionResult::failed('"offset_amount" must be a number, negative values included.');
        }

        // The data reference is checked after the test-mode branch on purpose:
        // a test run starts from an empty context, so the token resolves to nothing.
        if ($at === '' && $context->isTestMode()) {
            return ActionResult::success([
                'would_wait_until' => null,
                'waited' => false,
                'note' => 'Test mode — no point in time to wait for.',
            ]);
        }

        if ($at === '') {
            return ActionResult::missingDataReference('at', 'Point in time', '{{ booking.scheduled_at }}');
        }

        try {
            $base = Carbon::parse($at);
        } catch (\Throwable) {
            return ActionResult::failed(sprintf('"%s" is not a readable date and time.', $at));
        }

        $seconds = (int) round((float) $offset * $this->secondsPer($unit));
        $due = $base->copy()->addSeconds($seconds)->utc();
        $dueAt = $due->toIso8601String();

        if ($context->isTestMode()) {
            return ActionResult::success([
                'would_wait_until' => $dueAt,
                'waited' => false,
                'note' => 'Test mode — not paused.',
            ]);
        }

        if (! $due->isFuture()) {
            return ActionResult::success(['late' => true, 'waited' => false, 'due_at' => $dueAt]);
        }

        return ActionResult::wait(
            ['due_at' => $dueAt],
            ['late' => false, 'waited' => true, 'due_at' => $dueAt],
        );
    }

    protected function secondsPer(string $unit): int
    {
        return match ($unit) {
            self::UNIT_DAYS => 86400,
            self::UNIT_HOURS => 3600,
            default => 60,
        };
    }
}
