<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Support\RestartPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether an incoming trigger event becomes a run.
 *
 * One question, asked in one place, on the way into
 * {@see TriggerDispatcher::dispatch()}. It is deliberately not inside
 * `WorkflowRunner`: by the time a run exists the enrollment has already
 * happened, and "should this person be enrolled" is a question about a person
 * and an automation, not about a graph.
 *
 * The default answer is yes, unconditionally, which is what every automation
 * did before this class existed and what every automation still does until
 * somebody sets a policy on its trigger. See {@see RestartPolicy}.
 */
class EnrollmentGate
{
    public function __construct(protected TokenResolver $tokens) {}

    /**
     * May this event start a run, and under which subject?
     *
     * `subjectKey` comes back even when the answer is no, and even under
     * `always`, because the run row wants it either way: it is what the funnel
     * counts distinct people by, and what the *next* event will be compared
     * against.
     *
     * @return array{allowed: bool, subject_key: string|null, policy: RestartPolicy, reason: string|null}
     */
    public function evaluate(
        Automation $automation,
        AutomationNode $triggerNode,
        AutomationContext $context,
    ): array {
        $policy = RestartPolicy::fromValue(
            $this->stringConfig($triggerNode, RestartPolicy::CONFIG_KEY)
        );

        $subjectKey = $this->subjectKey($triggerNode, $context);

        if ($policy === RestartPolicy::Always) {
            return $this->verdict(true, $subjectKey, $policy);
        }

        // A policy that needs a subject and has none cannot be applied, so it
        // falls back to the behaviour that changes nothing. Treating every
        // subjectless run as "the same subject" would make one nightly sweep
        // block every later one for ever — a silent stop that looks exactly
        // like a broken trigger.
        if ($subjectKey === null) {
            Log::warning(
                "Automation [{$automation->handle}] has re-entry policy [{$policy->value}] but this "
                .'event names no contact, so the policy was not applied and the run was created as '
                .'usual. Set the trigger\'s "Contact identified by" field if this automation is '
                .'about a person.'
            );

            return $this->verdict(true, null, $policy, 'no_subject');
        }

        $priorRuns = AutomationRun::query()
            ->where('automation_uuid', $automation->uuid)
            ->where('subject_key', $subjectKey)
            ->where('is_test', false);

        if ($policy === RestartPolicy::Ignore) {
            return $priorRuns->exists()
                ? $this->verdict(false, $subjectKey, $policy, 'already_enrolled')
                : $this->verdict(true, $subjectKey, $policy);
        }

        if ($policy === RestartPolicy::Resume) {
            return $this->openRuns($automation, $subjectKey)->exists()
                ? $this->verdict(false, $subjectKey, $policy, 'already_running')
                : $this->verdict(true, $subjectKey, $policy);
        }

        // Restart is the only case left, and this is written as the fall-through
        // rather than as a fourth `if` on purpose: a fifth policy added to the
        // enum would then quietly cancel people's running passes. Left like
        // this, static analysis reports the new case as unhandled at the
        // declaration, which is where somebody can still decide what it should
        // do.
        return $this->cancelOpenRuns($automation, $subjectKey, $policy, $subjectKey);
    }

    /**
     * Cancel whatever pass is still open for this subject, then let the new one
     * through.
     *
     * The scheduled jobs go with the runs, and that is the part that is easy to
     * forget: a run parked in a two-day delay has a row in
     * `automation_scheduled_jobs` that will wake it up regardless of what its
     * status says. Cancelling the run without cancelling its wake-up call
     * produces exactly the thing this policy exists to prevent — the old pass
     * resuming days later, alongside the new one.
     *
     * @return array{allowed: bool, subject_key: string|null, policy: RestartPolicy, reason: string|null}
     */
    protected function cancelOpenRuns(
        Automation $automation,
        string $subjectKey,
        RestartPolicy $policy,
        string $subject,
    ): array {
        $openRunIds = $this->openRuns($automation, $subjectKey)->pluck('id')->all();

        if ($openRunIds === []) {
            return $this->verdict(true, $subject, $policy);
        }

        AutomationScheduledJob::query()
            ->whereIn('automation_run_id', $openRunIds)
            ->whereIn('status', [AutomationScheduledJob::STATUS_PENDING, AutomationScheduledJob::STATUS_QUEUED])
            ->update(['status' => AutomationScheduledJob::STATUS_CANCELLED]);

        AutomationRun::query()
            ->whereIn('id', $openRunIds)
            ->update([
                'status' => AutomationRun::STATUS_CANCELLED,
                'error_message' => 'Cancelled: the contact re-entered this automation and its re-entry policy is "restart".',
                'finished_at' => now(),
            ]);

        return $this->verdict(true, $subject, $policy, 'restarted');
    }

    /**
     * Runs of this subject that have not reached an end.
     *
     * @return Builder<AutomationRun>
     */
    protected function openRuns(Automation $automation, string $subjectKey)
    {
        return AutomationRun::query()
            ->where('automation_uuid', $automation->uuid)
            ->where('subject_key', $subjectKey)
            ->where('is_test', false)
            ->whereIn('status', [
                AutomationRun::STATUS_QUEUED,
                AutomationRun::STATUS_RUNNING,
                AutomationRun::STATUS_WAITING,
            ]);
    }

    /**
     * Who this event is about, normalised.
     *
     * Lower-cased and trimmed, because the value is almost always an address
     * and "the same person" must not depend on how a form was filled in. That
     * is the same rule the frequency cap applies, arrived at independently and
     * for the same reason.
     */
    public function subjectKey(AutomationNode $triggerNode, AutomationContext $context): ?string
    {
        $configured = $this->stringConfig($triggerNode, RestartPolicy::SUBJECT_CONFIG_KEY);

        if ($configured !== null && $configured !== '') {
            $resolved = str_contains($configured, '{{')
                ? $this->tokens->resolveString($configured, $context)
                : $context->get($configured, $configured);

            return $this->clean(is_scalar($resolved) ? (string) $resolved : null);
        }

        foreach (RestartPolicy::DEFAULT_SUBJECT_PATHS as $path) {
            $candidate = $context->get($path);

            if (is_string($candidate) && ($clean = $this->clean($candidate)) !== null) {
                return $clean;
            }
        }

        return null;
    }

    protected function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        // A token that resolved to nothing comes back as the empty string or as
        // the literal it could not replace. Neither is a person.
        return ($value === '' || str_contains($value, '{{')) ? null : $value;
    }

    protected function stringConfig(AutomationNode $node, string $key): ?string
    {
        $value = data_get($node->config ?? [], $key);

        return is_string($value) ? trim($value) : null;
    }

    /**
     * @return array{allowed: bool, subject_key: string|null, policy: RestartPolicy, reason: string|null}
     */
    protected function verdict(bool $allowed, ?string $subjectKey, RestartPolicy $policy, ?string $reason = null): array
    {
        return [
            'allowed' => $allowed,
            'subject_key' => $subjectKey,
            'policy' => $policy,
            'reason' => $reason,
        ];
    }
}
