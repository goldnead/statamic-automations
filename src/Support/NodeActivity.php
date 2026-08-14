<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Illuminate\Support\Collection;

/**
 * How many people reached each step of an automation, got through it, and
 * broke on it.
 *
 * `automation_node_runs` has held these facts since the first release and
 * nothing has ever read them: a flow could say how many people were in it
 * ({@see RunStats}) but not *where* they were, which is the only version of the
 * question that says what to fix. Five numbers for a nine-step series is a
 * verdict without a reason.
 *
 * Same two rules as RunStats, for the same reasons. Test runs are out, because
 * an editor pressing "test" is not somebody going through the flow. And there
 * is no new table: this is a GROUP BY over rows the engine already writes, on
 * the composite `2026_08_15_000001` added for it.
 *
 * **A node with no rows is absent from the result, not zero.** The canvas draws
 * whatever it is given, and a fresh automation whose every card reads "0 / 0 / 0"
 * looks broken rather than new. Absent means "nothing to say yet", and the card
 * says nothing.
 */
class NodeActivity
{
    /**
     * Per node, for one automation.
     *
     * `reached` counts every run that arrived, whatever became of it — at a
     * step is a fact of its own, and it is the number the funnel subtracts to
     * find where people stop. `completed` and `failed` are the two outcomes
     * worth naming; skipped and stopped rows are the difference between them
     * and `reached`, which is exactly what "did not get through here" means.
     *
     * @return array<string, array{reached: int, completed: int, failed: int}>
     *                                                                         keyed by node_key, in no
     *                                                                         particular order — the caller
     *                                                                         owns the order, because only the
     *                                                                         graph knows it
     */
    public function forAutomation(
        string $automationUuid,
        ?ActivityWindow $window = null,
        bool $includeTests = false,
    ): array {
        if ($automationUuid === '') {
            return [];
        }

        // Distinct runs, not rows. A run is one enrolment — one subject, one
        // pass through the automation — so counting runs is counting people,
        // which is what the screen says it shows.
        //
        // `COUNT(*)` was not that. An inline loop calls `runFrom()` once per
        // iteration and writes a row per body node per pass, and a wait-until
        // node is written again when the run resumes. A loop over ten items
        // reported its body node ten times over, and because the bars are
        // drawn against the busiest node, every other step then shrank to a
        // fraction of it — the view drew a collapse exactly where none had
        // happened, which is the one question it exists to answer.
        //
        // Counted per node rather than per (node, status): a run that failed a
        // node and succeeded on a retry reached it once, not twice.
        $success = AutomationNodeRun::STATUS_SUCCESS;
        $failed = AutomationNodeRun::STATUS_FAILED;

        $query = AutomationNodeRun::query()
            ->selectRaw(
                'node_key,'
                .' COUNT(DISTINCT automation_run_id) as reached,'
                .' COUNT(DISTINCT CASE WHEN status = ? THEN automation_run_id END) as completed,'
                .' COUNT(DISTINCT CASE WHEN status = ? THEN automation_run_id END) as failed',
                [$success, $failed],
            )
            ->where('automation_uuid', $automationUuid)
            ->groupBy('node_key');

        if (! $includeTests) {
            $query->where('is_test', false);
        }

        $window?->apply($query);

        $totals = [];

        /** @var Collection<int, object> $rows */
        $rows = $query->get();

        foreach ($rows as $row) {
            $totals[(string) $row->node_key] = [
                'reached' => (int) $row->reached,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
            ];
        }

        return $totals;
    }
}
