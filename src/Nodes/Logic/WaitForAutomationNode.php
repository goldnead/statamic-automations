<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\EnrollmentGate;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;

/**
 * Holds this run while the same person is still inside a DIFFERENT automation.
 *
 * Two series that mail the same person must not collide: the person who is
 * halfway through the welcome series should not also get the first mail of the
 * next one. This node sits at the start of the second series, waits until the
 * first one is done with her, and tells the graph how it ended.
 *
 * ## What it reads
 *
 * Runs of the person (`subject_key`, the lower-cased address, exactly as
 * {@see EnrollmentGate} writes it) in the
 * target automation, test runs left out, this run itself left out. Nothing is
 * written.
 *
 * ## The answer (`state`)
 *
 *  - `opted_out`: the person left the target series
 *    ({@see SequenceOptOut}). Checked FIRST and counts as ended at once, even
 *    when the target run is still parked on its next send step: it will not
 *    send, so there is nothing to wait for.
 *  - `none`: the person never had a run there.
 *  - `ran_to_end`: the newest run finished with status success.
 *  - `ended_early`: the newest run was stopped, failed or cancelled.
 *
 * `ran_to_end` (bool) is true only for `ran_to_end`: success AND no opt-out.
 *
 * A run that is queued, running or waiting is not an answer yet: the node parks
 * this run and asks again after `poll_hours`.
 *
 * ## Waiting for a run that starts a moment later
 *
 * Two series triggered by the same event start within seconds of each other, and
 * which one the queue picks first is luck. With `wait_if_missing_minutes` set and
 * no run in the target yet, the node waits until that many minutes have passed
 * since the start of THIS run (`_run.started_at`, put there by the runner), so
 * the other run has had time to appear. Counted from the start of the run, not
 * from the moment the node runs, so re-checking never pushes the deadline out.
 *
 * ## One mail a day (`settle`)
 *
 * With `next_day`, a target run that ran to the end holds this run until the next
 * calendar day after the day it finished, in `automations.timezone`. Only a run
 * to the end earns the extra day; an opt-out or an early end does not.
 *
 * ## It re-runs on resume
 *
 * Like {@see WaitUntilNode}: when a parked check fires it is evaluated again,
 * because the other run may still be open. {@see WorkflowRunner::resumeAfterNode()}.
 *
 * ## Test mode
 *
 * Passes straight through with `none`. A preview has no other series to wait for.
 */
class WaitForAutomationNode implements AutomationLogicNode
{
    public const SETTLE_NONE = 'none';

    public const SETTLE_NEXT_DAY = 'next_day';

    public const DEFAULT_POLL_HOURS = 6;

    public const DEFAULT_TIMEZONE = 'Europe/Berlin';

    public function __construct(protected SequenceOptOut $optOuts) {}

    public static function handle(): string
    {
        return 'wait_for_automation';
    }

    public static function label(): string
    {
        return 'Wait for Automation';
    }

    public static function description(): ?string
    {
        return 'Pauses while the same person is still inside another automation, then reports how that one ended.';
    }

    public static function group(): string
    {
        return 'Logic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    /**
     * The other run may still be open when a parked check fires, so the node is
     * evaluated again instead of being skipped past.
     */
    public static function reexecuteOnResume(): bool
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
                'help' => 'The person, for example {{ contact.email }}. Runs are matched by the lower-cased address.',
            ],
            [
                'handle' => 'automation',
                'label' => 'Wait for automation',
                'type' => 'text',
                'required' => true,
                'help' => 'Handle (or uuid) of the other automation.',
            ],
            [
                'handle' => 'poll_hours',
                'label' => 'Ask again every (hours)',
                'type' => 'number',
                'required' => false,
                'default' => self::DEFAULT_POLL_HOURS,
                'help' => 'How often to look again while the other run is still going.',
            ],
            [
                'handle' => 'wait_if_missing_minutes',
                'label' => 'If there is no run yet, wait up to (minutes)',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'help' => 'Counted from the start of this run, so a run that starts seconds later is not missed. 0 = do not wait.',
            ],
            [
                'handle' => 'settle',
                'label' => 'After the other run ended',
                'type' => 'select',
                'required' => false,
                'default' => self::SETTLE_NONE,
                'options' => [
                    self::SETTLE_NONE => 'Continue at once',
                    self::SETTLE_NEXT_DAY => 'Wait until the next calendar day',
                ],
                'help' => 'Next day keeps two mails from reaching the same person on one day. Uses automations.timezone.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'state' => 'string',
            'ran_to_end' => 'boolean',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        if ($context->isTestMode()) {
            return ActionResult::success(self::answer('none') + ['note' => 'Test mode — not waiting.']);
        }

        $email = mb_strtolower(trim((string) ($config['email'] ?? '')));
        $ref = trim((string) ($config['automation'] ?? ''));
        $settle = trim((string) ($config['settle'] ?? '')) ?: self::SETTLE_NONE;

        if ($ref === '') {
            return ActionResult::failed('"automation" is required: the handle of the automation to wait for.');
        }

        if ($email === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ contact.email }}');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failed('A valid "email" is required.');
        }

        if (! in_array($settle, [self::SETTLE_NONE, self::SETTLE_NEXT_DAY], true)) {
            return ActionResult::failed(sprintf('Unknown settle "%s": use "none" or "next_day".', $settle));
        }

        $pollHours = $this->number($config['poll_hours'] ?? null, self::DEFAULT_POLL_HOURS);
        $graceMinutes = $this->number($config['wait_if_missing_minutes'] ?? null, 0);

        if ($pollHours === null || $pollHours <= 0) {
            return ActionResult::failed('"poll_hours" must be a number above 0.');
        }

        if ($graceMinutes === null || $graceMinutes < 0) {
            return ActionResult::failed('"wait_if_missing_minutes" must be 0 or more.');
        }

        $target = app(AutomationRepository::class)->findByRef($ref);

        if (! $target instanceof Automation) {
            return ActionResult::failed(sprintf('Automation "%s" was not found.', $ref));
        }

        $targetUuid = (string) $target->uuid;

        // First, and above everything: somebody who left the series is done with
        // it, however the run itself is doing.
        if ($this->optOuts->has($targetUuid, $email)) {
            return ActionResult::success(self::answer('opted_out'));
        }

        $ownUuid = (string) ($context->get('_run.uuid') ?? '');
        $runs = AutomationRun::query()
            ->where('automation_uuid', $targetUuid)
            ->where('subject_key', $email)
            ->where('is_test', false)
            ->when($ownUuid !== '', fn ($q) => $q->where('uuid', '!=', $ownUuid));

        $open = (clone $runs)->whereIn('status', [
            AutomationRun::STATUS_QUEUED,
            AutomationRun::STATUS_RUNNING,
            AutomationRun::STATUS_WAITING,
        ])->exists();

        if ($open) {
            return ActionResult::wait(
                ['seconds' => max(60, (int) round($pollHours * 3600))],
                ['state' => 'waiting', 'ran_to_end' => false, 'reason' => 'run_in_progress'],
            );
        }

        /** @var AutomationRun|null $latest */
        $latest = (clone $runs)->orderByDesc('created_at')->orderByDesc('id')->first();

        if ($latest === null) {
            return $this->noRunYet($context, $graceMinutes);
        }

        if ($latest->status !== AutomationRun::STATUS_SUCCESS) {
            return ActionResult::success(self::answer('ended_early'));
        }

        if ($settle === self::SETTLE_NEXT_DAY && ($due = $this->nextDayAfter($latest)) !== null && now()->lt($due)) {
            return ActionResult::wait(
                ['due_at' => $due->utc()->toIso8601String()],
                ['state' => 'waiting', 'ran_to_end' => false, 'reason' => 'settling_until_next_day'],
            );
        }

        return ActionResult::success(self::answer('ran_to_end'));
    }

    /**
     * No run of this person yet. Within the grace minutes of this run's own
     * start the other run may simply not have been created, so wait for it;
     * after them, believe that it is not coming.
     */
    protected function noRunYet(AutomationContext $context, float $graceMinutes): ActionResult
    {
        $started = $context->get('_run.started_at');

        if ($graceMinutes > 0 && is_string($started) && $started !== '') {
            $due = Carbon::parse($started)->addSeconds((int) round($graceMinutes * 60));

            if (now()->lt($due)) {
                return ActionResult::wait(
                    ['due_at' => $due->utc()->toIso8601String()],
                    ['state' => 'waiting', 'ran_to_end' => false, 'reason' => 'no_run_yet'],
                );
            }
        }

        return ActionResult::success(self::answer('none'));
    }

    /**
     * The first instant of the calendar day after the one the run finished on,
     * in the configured time zone. Null when the run has no end time to count from.
     */
    protected function nextDayAfter(AutomationRun $run): ?Carbon
    {
        $finished = $run->finished_at;

        if ($finished === null) {
            return null;
        }

        $zone = (string) (config('automations.timezone') ?: self::DEFAULT_TIMEZONE);

        try {
            return Carbon::instance($finished)->setTimezone($zone)->startOfDay()->addDay();
        } catch (\Throwable) {
            return Carbon::instance($finished)->setTimezone(self::DEFAULT_TIMEZONE)->startOfDay()->addDay();
        }
    }

    protected function number(mixed $value, int|float $default): int|float|null
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * @return array{state: string, ran_to_end: bool}
     */
    protected static function answer(string $state): array
    {
        return ['state' => $state, 'ran_to_end' => $state === 'ran_to_end'];
    }
}
