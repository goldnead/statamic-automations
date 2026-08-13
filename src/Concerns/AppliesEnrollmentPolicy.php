<?php

namespace Goldnead\StatamicAutomations\Concerns;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\EnrollmentGate;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;

/**
 * Ask the re-entry gate before a listener turns an event into a run.
 *
 * {@see EnrollmentGate} was written for {@see TriggerDispatcher},
 * and until this trait existed that was the only place it was asked. The four
 * dedicated listeners — marketing, LeadHub, form submissions, entry publishes —
 * each build their own context and call `createRun()` directly, so for every
 * automation started by one of them the policy on the trigger node was read by
 * nobody.
 *
 * That failure is silent in the worst way: the field is in the config, the
 * control panel shows the choice, an exported automation carries it, and
 * nothing happens. `marketing.subscribed` is the trigger a welcome sequence
 * starts from and the one where `ignore` matters most — somebody who
 * unsubscribes and subscribes again gets the whole sequence a second time,
 * in parallel with the first, both still ticking. That is the exact case the
 * policy exists to prevent.
 *
 * A listener that uses this trait behaves identically to before for every
 * automation on the default `always`, which is all of them until somebody
 * chooses otherwise.
 */
trait AppliesEnrollmentPolicy
{
    /**
     * The run this event should start, or null if the policy says it should
     * not start one.
     *
     * The runner is handed in rather than read off the host class: every
     * listener holds its own, and a trait that reaches into a property it did
     * not declare breaks the moment somebody renames it.
     */
    protected function createEnrolledRun(
        WorkflowRunner $runner,
        Automation $automation,
        AutomationNode $triggerNode,
        AutomationContext $context,
    ): ?AutomationRun {
        $verdict = app(EnrollmentGate::class)->evaluate($automation, $triggerNode, $context);

        if (! $verdict['allowed']) {
            return null;
        }

        // `subject_key` comes back even under `always`, and it is passed on
        // for the same reason the dispatcher passes it: it is what the funnel
        // counts distinct people by, and what the *next* event of this person
        // will be compared against.
        return $runner->createRun($automation, $context, $triggerNode, $verdict['subject_key']);
    }
}
