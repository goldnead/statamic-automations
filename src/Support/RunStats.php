<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Collection;

/**
 * How many people are in an automation, how many got through it, and how many
 * left along the way.
 *
 * **No new table.** Every one of these numbers was already in `automation_runs`
 * before this class existed; what was missing was somebody asking. A run is an
 * enrollment — one subject, one pass through one automation — so counting runs
 * by status *is* the funnel, and a second table recording the same facts would
 * be a second place for them to disagree.
 *
 * What was genuinely missing is an index. `automation_runs` carried
 * `automation_uuid` and `status` as two separate single-column indexes, which
 * answers "this automation's runs" and "everything that failed" but makes the
 * one query this class runs — group this automation's runs by status — a scan
 * of every run the automation ever had. On an install with a year of a daily
 * welcome series that is the difference between a listing that loads and one
 * that times out. The composite is added in
 * `2026_08_03_000001_add_sequence_columns_to_automation_runs_table`.
 *
 * **Test runs are excluded by default**, because an editor pressing "test" is
 * not a person going through the flow, and a completion rate that counted them
 * would read best on the automations nobody ever sent to anybody.
 */
class RunStats
{
    /** Still inside the automation: queued to start, running, or parked in a delay. */
    public const IN_PROGRESS = [
        AutomationRun::STATUS_QUEUED,
        AutomationRun::STATUS_RUNNING,
        AutomationRun::STATUS_WAITING,
    ];

    /** Reached the end of the graph. */
    public const COMPLETED = [
        AutomationRun::STATUS_SUCCESS,
    ];

    /**
     * Left before the end, without an error.
     *
     * A Stop node, a filter that did not match, a send node that found no
     * consent — and, since 1.9, a restart policy that cancelled an old pass.
     * All of them are "this person is no longer in the flow", which is a
     * different fact from "this person finished it" and a different one again
     * from "something broke".
     */
    public const EXITED = [
        AutomationRun::STATUS_STOPPED,
        AutomationRun::STATUS_CANCELLED,
    ];

    public const FAILED = [
        AutomationRun::STATUS_FAILED,
    ];

    /**
     * The funnel for one automation.
     *
     * @return array{enrolled: int, in_progress: int, completed: int, exited: int, failed: int}
     */
    public function forAutomation(string $automationUuid, bool $includeTests = false): array
    {
        return $this->forAutomations([$automationUuid], $includeTests)[$automationUuid]
            ?? $this->empty();
    }

    /**
     * The same funnel for many automations, in one query.
     *
     * The listing screen shows a row per automation, and asking per row is how
     * a page with thirty automations makes thirty-one queries. One GROUP BY
     * over (automation_uuid, status) is the whole answer, and it is exactly the
     * shape of the composite index.
     *
     * @param  array<int, string>  $automationUuids
     * @return array<string, array{enrolled: int, in_progress: int, completed: int, exited: int, failed: int}>
     */
    public function forAutomations(array $automationUuids, bool $includeTests = false): array
    {
        $uuids = array_values(array_unique(array_filter(
            array_map('strval', $automationUuids),
            fn (string $uuid) => $uuid !== '',
        )));

        if ($uuids === []) {
            return [];
        }

        $query = AutomationRun::query()
            ->selectRaw('automation_uuid, status, COUNT(*) as aggregate')
            ->whereIn('automation_uuid', $uuids)
            ->groupBy('automation_uuid', 'status');

        if (! $includeTests) {
            $query->where('is_test', false);
        }

        $totals = [];

        foreach ($uuids as $uuid) {
            $totals[$uuid] = $this->empty();
        }

        /** @var Collection<int, object> $rows */
        $rows = $query->get();

        foreach ($rows as $row) {
            $uuid = (string) $row->automation_uuid;
            $status = (string) $row->status;
            $count = (int) $row->aggregate;

            if (! isset($totals[$uuid])) {
                continue;
            }

            $totals[$uuid]['enrolled'] += $count;

            $bucket = match (true) {
                in_array($status, self::IN_PROGRESS, true) => 'in_progress',
                in_array($status, self::COMPLETED, true) => 'completed',
                in_array($status, self::EXITED, true) => 'exited',
                in_array($status, self::FAILED, true) => 'failed',
                // A status from a later release counts towards `enrolled` and
                // nothing else. Silently folding it into one of the four
                // buckets would be a guess, and the four are meant to add up.
                default => null,
            };

            if ($bucket !== null) {
                $totals[$uuid][$bucket] += $count;
            }
        }

        return $totals;
    }

    /**
     * How many distinct subjects this automation has ever enrolled.
     *
     * Different from `enrolled`, and the difference is the point of the
     * restart policy: a flow on `always` counts the same person once per
     * re-entry, and this is how many people that actually was. Null-keyed rows
     * (a trigger whose subject could not be named) are not counted, because
     * "unknown" is not a person.
     */
    public function distinctSubjects(string $automationUuid, bool $includeTests = false): int
    {
        $query = AutomationRun::query()
            ->where('automation_uuid', $automationUuid)
            ->whereNotNull('subject_key');

        if (! $includeTests) {
            $query->where('is_test', false);
        }

        return (int) $query->distinct()->count('subject_key');
    }

    /**
     * @return array{enrolled: int, in_progress: int, completed: int, exited: int, failed: int}
     */
    protected function empty(): array
    {
        return [
            'enrolled' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'exited' => 0,
            'failed' => 0,
        ];
    }
}
