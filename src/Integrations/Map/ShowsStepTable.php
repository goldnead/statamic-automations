<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitForAutomationNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilTimeNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Wo springen sie ab?": the step table in the panel of an automation on the map.
 *
 * Read only, by design: a lookup that opens a panel must count nothing and write nothing.
 *
 * **Who is counted.** The people who started in the period (runs of the period, no test
 * runs), once each: a person is the run's `subject_key`, the lower-cased address. Runs that
 * failed with "No Recipient to act on" are left out and said so: a parallel trigger on a
 * lead-magnet contact starts a run nobody is behind, and counting it would show a drop-off
 * that is none. What a mail did to them comes from the message the node run names
 * (`output.message_uuid`), counted by the marketing addon; its `machine` flag keeps an
 * automatic open (Apple Mail Privacy Protection) apart from a person's.
 *
 * **Waits.** Consecutive waits between two mails (a delay, the newsletter-day gate, ...) are
 * one row. People in it are those with a pending job on one of its nodes.
 *
 * @phpstan-require-extends AutomationMapObjects
 */
trait ShowsStepTable
{
    /** The marketing addon's counter; optional, asked through the container. */
    private const ENGAGEMENT = 'Goldnead\\Marketing\\Services\\MessageEngagement';

    /** Nodes that make a run wait, for a row of their own. */
    private function waitTypes(): array
    {
        return [DelayNode::handle(), 'newsletter_day_gate', WaitUntilNode::handle(), WaitUntilTimeNode::handle(), WaitForAutomationNode::handle()];
    }

    /** SQLite takes a limited number of bound values; runs and uuids are read in chunks of this size. */
    private const STEP_CHUNK = 500;

    /**
     * What marketing says became of these messages, or null when it cannot say (addon missing or failing):
     * the table then shows how many were sent and nothing about opens.
     *
     * @param  list<string>  $messageUuids
     * @return array{sent: int, opened: int, proxy_only: int, clicked: int, unsubscribed: int}|null
     */
    protected function engagementOf(array $messageUuids): ?array
    {
        if (! class_exists(self::ENGAGEMENT)) {
            return null;
        }

        try {
            $counter = app(self::ENGAGEMENT);

            return method_exists($counter, 'forMessages') ? $counter->forMessages($messageUuids) : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The two blocks of the table, or null when nobody started in the period.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function stepBlocks(Automation $automation, MapPeriod $period): ?array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, AutomationRun> $runs */
        $runs = $this->periodRuns($automation, $period)->get(['id', 'subject_key', 'status']);

        if ($runs->isEmpty()) {
            return null;
        }

        $mailTypes = [SendEmailAction::handle(), self::MARKETING_SEND];

        // Runs nobody is behind are told apart from the real ones by what the node run says it lacked.
        $noise = [];
        $stoppedAt = [];
        $sent = [];
        $reached = [];

        foreach ($runs->pluck('id')->chunk(self::STEP_CHUNK) as $ids) {
            $nodeRuns = AutomationNodeRun::query()
                ->whereIn('automation_run_id', $ids->all())
                ->whereIn('node_type', $mailTypes)
                ->get(['automation_run_id', 'node_key', 'status', 'output']);

            foreach ($nodeRuns as $nodeRun) {
                $output = is_array($nodeRun->output) ? $nodeRun->output : [];

                if ($nodeRun->status === AutomationNodeRun::STATUS_FAILED && isset($output['missing_data_reference'])) {
                    $noise[$nodeRun->automation_run_id] = true;
                }
            }

            foreach ($nodeRuns as $nodeRun) {
                if (isset($noise[$nodeRun->automation_run_id])) {
                    continue;
                }

                $output = is_array($nodeRun->output) ? $nodeRun->output : [];

                if (in_array($nodeRun->status, [AutomationNodeRun::STATUS_STOPPED, AutomationNodeRun::STATUS_FAILED], true)) {
                    $stoppedAt[$nodeRun->node_key][$nodeRun->automation_run_id] = true;
                } elseif ($nodeRun->status === AutomationNodeRun::STATUS_SUCCESS) {
                    $reached[$nodeRun->node_key][$nodeRun->automation_run_id] = true;

                    if (is_string($output['message_uuid'] ?? null) && $output['message_uuid'] !== '') {
                        $sent[$nodeRun->node_key][] = $output['message_uuid'];
                    }
                }
            }
        }

        $real = $runs->reject(fn (AutomationRun $run) => isset($noise[$run->id]))->values();
        $person = fn (AutomationRun $run): string => ($key = mb_strtolower(trim((string) $run->subject_key))) !== '' ? $key : 'run:'.$run->id;
        $personOfRun = $real->mapWithKeys(fn (AutomationRun $run) => [$run->id => $person($run)])->all();

        $peopleAt = fn (array $runIds): int => count(array_unique(array_map(fn ($id) => $personOfRun[$id], array_filter(array_keys($runIds), fn ($id) => isset($personOfRun[$id])))));

        $jobs = [];

        foreach ($real->pluck('id')->chunk(self::STEP_CHUNK) as $ids) {
            foreach (AutomationScheduledJob::query()
                ->whereIn('automation_run_id', $ids->all())
                ->whereIn('status', [AutomationScheduledJob::STATUS_PENDING, AutomationScheduledJob::STATUS_QUEUED])
                ->get(['automation_run_id', 'node_key', 'due_at']) as $job) {
                $jobs[(string) $job->getAttribute('node_key')][] = $job;
            }
        }

        $automation->loadMissing(['nodes', 'edges']);
        [$rows, $unsubscribed] = $this->stepRows($automation, $peopleAt, $stoppedAt, $reached, $sent, $jobs, $personOfRun);

        return [
            [
                'kind' => 'list',
                'title' => __('statamic-automations::automations.map_steps_title', ['period' => $period->label()]),
                'items' => $rows,
                'more' => null,
            ],
            [
                'kind' => 'facts',
                'title' => __('statamic-automations::automations.map_steps_fate_title'),
                'rows' => $this->fateRows($real, $personOfRun, count($noise), $unsubscribed),
            ],
        ];
    }

    /**
     * @param  callable(array<int|string, bool>): int  $peopleAt
     * @param  array<string, array<int, bool>>  $stoppedAt
     * @param  array<string, array<int, bool>>  $reached
     * @param  array<string, list<string>>  $sent
     * @param  array<string, list<AutomationScheduledJob>>  $jobs
     * @param  array<int, string>  $personOfRun
     * @return array{0: list<array<string, mixed>>, 1: int} the rows and the people who unsubscribed
     */
    private function stepRows(Automation $automation, callable $peopleAt, array $stoppedAt, array $reached, array $sent, array $jobs, array $personOfRun): array
    {
        $order = $this->stepOrder($automation);
        $mailTypes = [SendEmailAction::handle(), self::MARKETING_SEND];

        $slugs = [];

        foreach ($order as $node) {
            if (in_array($node->type, $mailTypes, true) && $this->writtenSubject($node) === null && ($slug = $this->writtenTemplate($node)) !== null) {
                $slugs[] = $slug;
            }
        }

        $subjects = $slugs === [] ? [] : $this->templateSubjects(array_values(array_unique($slugs)));

        $rows = [];
        $wait = [];
        $unsubscribed = 0;

        $flush = function () use (&$wait, &$rows, $jobs, $personOfRun): void {
            if (count($wait) > 0) {
                $rows[] = $this->waitRow($wait, $jobs, $personOfRun);
                $wait = [];
            }
        };

        foreach ($order as $node) {
            if (in_array($node->type, $this->waitTypes(), true)) {
                $wait[] = $node;

                continue;
            }

            if (! in_array($node->type, $mailTypes, true)) {
                continue;
            }

            $flush();

            $uuids = $sent[$node->node_key] ?? [];
            $counts = $node->type === self::MARKETING_SEND
                ? ($uuids === [] ? ['sent' => 0, 'opened' => 0, 'proxy_only' => 0, 'clicked' => 0, 'unsubscribed' => 0] : $this->engagementOf($uuids))
                : null;
            $unsubscribed += (int) ($counts['unsubscribed'] ?? 0);

            $parts = [__('statamic-automations::automations.map_steps_sent', ['count' => $counts['sent'] ?? $peopleAt($reached[$node->node_key] ?? [])])];

            if ($counts !== null) {
                $parts[] = $counts['proxy_only'] > 0
                    ? __('statamic-automations::automations.map_steps_opened_proxy', ['count' => $counts['opened'], 'proxy' => $counts['proxy_only']])
                    : __('statamic-automations::automations.map_steps_opened', ['count' => $counts['opened']]);
                $parts[] = __('statamic-automations::automations.map_steps_clicked', ['count' => $counts['clicked']]);

                if ($counts['unsubscribed'] > 0) {
                    $parts[] = __('statamic-automations::automations.map_steps_unsubscribed', ['count' => $counts['unsubscribed']]);
                }
            }

            if (($here = $peopleAt($stoppedAt[$node->node_key] ?? [])) > 0) {
                $parts[] = __('statamic-automations::automations.map_steps_stopped_here', ['count' => $here]);
            }

            $rows[] = ['title' => Str::limit($this->mailTitle($node, $subjects), 120), 'meta' => implode(' · ', $parts), 'image' => null];
        }

        $flush();

        return [$rows, $unsubscribed];
    }

    /**
     * @param  list<AutomationNode>  $wait
     * @param  array<string, list<AutomationScheduledJob>>  $jobs
     * @param  array<int, string>  $personOfRun
     * @return array<string, mixed>
     */
    private function waitRow(array $wait, array $jobs, array $personOfRun): array
    {
        $seconds = 0;
        $people = [];
        $due = null;

        foreach ($wait as $node) {
            if ($node->type === DelayNode::handle()) {
                $seconds += $this->delaySeconds($node);
            }

            foreach ($jobs[$node->node_key] ?? [] as $job) {
                $who = $personOfRun[(int) $job->getAttribute('automation_run_id')] ?? null;
                $at = $job->getAttribute('due_at');

                if ($who !== null) {
                    $people[$who] = true;
                    $due = $at instanceof Carbon && ($due === null || $at->lt($due)) ? $at : $due;
                }
            }
        }

        $meta = $people === []
            ? __('statamic-automations::automations.map_steps_waiting_nobody')
            : __('statamic-automations::automations.map_steps_waiting', ['count' => count($people)]);

        if ($people !== [] && $due !== null) {
            $meta .= ' · '.__('statamic-automations::automations.map_steps_next_due', ['when' => $this->dueWords($due)]);
        }

        return [
            'title' => $seconds > 0
                ? __('statamic-automations::automations.map_steps_wait', ['wait' => $this->waitWords($seconds)])
                : __('statamic-automations::automations.map_steps_wait_other'),
            'meta' => $meta,
            'image' => null,
        ];
    }

    /** "21. Okt., 16:46" in the Control Panel's display time zone. */
    private function dueWords(Carbon $due): string
    {
        $zone = config('statamic.system.display_timezone') ?: config('app.timezone');

        return $due->copy()->setTimezone((string) $zone)->locale(app()->getLocale())->isoFormat('D. MMM, HH:mm');
    }

    /**
     * @param  Collection<int, AutomationRun>  $real
     * @param  array<int, string>  $personOfRun
     * @return list<array{label: string, value: string, href: null}>
     */
    private function fateRows($real, array $personOfRun, int $withoutRecipient, int $unsubscribed): array
    {
        $people = fn (iterable $runs): int => count(array_unique(array_map(fn (AutomationRun $run) => $personOfRun[$run->id], [...$runs])));
        $words = fn (int $n): string => trans_choice('statamic-automations::automations.map_steps_people', $n, ['count' => $n]);
        $of = fn (array $statuses) => $real->filter(fn (AutomationRun $run) => in_array($run->status, $statuses, true));
        $t = fn (string $key): string => __('statamic-automations::automations.'.$key);

        $rows = [['label' => $t('map_steps_started'), 'value' => $words($people($real))]];

        foreach ([
            ['map_steps_still_waiting', [AutomationRun::STATUS_WAITING, AutomationRun::STATUS_RUNNING, AutomationRun::STATUS_QUEUED]],
            ['map_steps_finished', [AutomationRun::STATUS_SUCCESS]],
        ] as [$label, $statuses]) {
            if (($n = $people($of($statuses))) > 0) {
                $rows[] = ['label' => $t($label), 'value' => $words($n)];
            }
        }

        if ($unsubscribed > 0) {
            $rows[] = ['label' => $t('map_steps_unsubscribed_row'), 'value' => $words($unsubscribed)];
        }

        foreach ([
            ['map_steps_stopped', [AutomationRun::STATUS_STOPPED]],
            ['map_steps_cancelled', [AutomationRun::STATUS_CANCELLED]],
            ['map_steps_failed', [AutomationRun::STATUS_FAILED]],
        ] as [$label, $statuses]) {
            if (($n = $people($of($statuses))) > 0) {
                $rows[] = ['label' => $t($label), 'value' => $words($n)];
            }
        }

        if ($withoutRecipient > 0) {
            $rows[] = ['label' => $t('map_steps_no_recipient'), 'value' => trans_choice('statamic-automations::automations.map_steps_runs', $withoutRecipient, ['count' => $withoutRecipient])];
        }

        $rows[] = ['label' => $t('map_steps_counted'), 'value' => $t('map_steps_counted_text')];

        return array_map(fn (array $row) => $row + ['href' => null], $rows);
    }
}
