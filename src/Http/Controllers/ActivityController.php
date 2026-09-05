<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActivityWindow;
use Goldnead\StatamicAutomations\Support\NodeActivity;
use Goldnead\StatamicAutomations\Support\RunStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Statamic\CP\Column;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an automation has actually been doing.
 *
 * Four readings of the same rows, all four narrowed by the same
 * {@see ActivityWindow}: the funnel and the per-node numbers (`overview`), the
 * step-by-step protocol (`nodeRuns`), the people currently inside the flow
 * (`subjects`), and the protocol again as a file (`export`).
 *
 * JSON rather than Inertia props, and that is a deliberate departure from the
 * builder page these hang off. The activity view lives inside
 * `Automations/Edit`, whose controller loads the graph, the node registry and
 * the mail projection on every render; re-running all of it to turn one page of
 * a log table would cost more than the table. The `api.*` group is documented at
 * the top of `routes/cp.php` as exactly this: endpoints that answer with data,
 * never with a page. `Listing` in server mode speaks that shape natively.
 */
class ActivityController extends Controller
{
    /** Rows per page, and the size of one export chunk. */
    private const PER_PAGE = 25;

    private const EXPORT_CHUNK = 500;

    /**
     * The funnel and the per-node numbers, for one window.
     *
     * Both are also handed to the builder page as props on first render (see
     * AutomationsPageController::edit) — the canvas has to be able to paint its
     * numbers without waiting for a round trip. This endpoint is what answers
     * every *change* of window after that.
     */
    public function overview(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automation runs');

        $window = ActivityWindow::fromRequest($request);

        return response()->json(
            static::payload($automationFlow, $window)
        );
    }

    /**
     * The funnel, the node numbers and everything the screen needs to render
     * them, as one array. Shared with the page controller so the prop and the
     * endpoint can never describe the same automation differently.
     *
     * @return array<string, mixed>
     */
    public static function payload(Automation $automation, ActivityWindow $window): array
    {
        $uuid = (string) $automation->uuid;

        return [
            'range' => $window->range,
            'funnel' => app(RunStats::class)->forAutomation($uuid, false, $window),
            'nodes' => app(NodeActivity::class)->forAutomation($uuid, $window),
            // Runs in flight that are about nobody — a scheduled sweep, a
            // webhook with no address in it. They belong to the funnel and not
            // to the list of people, so the list states how many it is not
            // showing rather than quietly disagreeing with the number above it.
            //
            // The one figure in this otherwise windowed payload that is **not**
            // windowed, because it is read on the people tab, which asks who is
            // inside the flow now. Windowed it would have hidden the same
            // long-parked runs the list itself was hiding, so the count would
            // not even have hinted that anything was missing.
            'without_subject' => (int) AutomationRun::query()
                ->where('automation_uuid', $uuid)
                ->where('is_test', false)
                ->whereIn('status', RunStats::IN_PROGRESS)
                ->whereNull('subject_key')
                ->count(),
        ];
    }

    /**
     * The protocol: one row per node run, newest first.
     *
     * Really paginated, not `limit(200)`. Every other listing in this addon
     * truncates at a fixed row count with nothing on screen to say so, which is
     * survivable for a list of automations and not for a log — the row somebody
     * opens this screen to find is the one that fell off the end.
     */
    public function nodeRuns(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automation runs');

        $window = ActivityWindow::fromRequest($request);
        $query = $this->logQuery($automationFlow, $request, $window);

        $perPage = min(max((int) $request->query('perPage', self::PER_PAGE), 1), 100);
        $paginator = $query->with('run:id,subject_key')->paginate($perPage)->withQueryString();

        $labels = $this->nodeLabels($automationFlow);

        /** @var Collection<int, AutomationNodeRun> $rows */
        $rows = collect($paginator->items());

        return response()->json([
            'data' => $rows->map(fn (AutomationNodeRun $run) => $this->logRow($run, $labels))->all(),
            'meta' => [
                'columns' => static::logColumns(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * The same rows, as a file.
     *
     * Same query, same filters, same order — it is the same method building
     * both, so "the export matches what I was looking at" is a property of the
     * code rather than a promise. Streamed and walked by key rather than by
     * offset: an export of a growing table read with LIMIT/OFFSET repeats and
     * skips rows as new ones land at the front.
     */
    public function export(Request $request, Automation $automationFlow): StreamedResponse
    {
        $this->authorizeAction('view automation runs');

        $window = ActivityWindow::fromRequest($request);
        $query = $this->logQuery($automationFlow, $request, $window);
        $labels = $this->nodeLabels($automationFlow);
        $ascending = $this->ascending($request);

        $filename = sprintf(
            '%s-activity-%s-%s.csv',
            $automationFlow->handle ?: 'automation',
            $window->range,
            now()->format('Y-m-d'),
        );

        return response()->streamDownload(function () use ($query, $labels, $ascending) {
            $handle = fopen('php://output', 'w');

            // Excel reads a file without this as the machine's legacy encoding,
            // so a step called "Willkommensgruß" arrives as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            $this->putCsv($handle, array_map(
                fn (array $column) => (string) ($column['label'] ?? $column['field'] ?? ''),
                static::logColumns(),
            ));

            // Where the last chunk stopped, as the pair the order is built on.
            // A single-column cursor would drop rows: several nodes of one run
            // share a whole-second `created_at`, and a chunk boundary that lands
            // inside such a group skips the rest of it.
            $cursor = null;

            while (true) {
                /** @var Collection<int, AutomationNodeRun> $page */
                $page = (clone $query)
                    ->when($cursor !== null, fn (Builder $q) => $q->where(
                        fn (Builder $inner) => $inner
                            ->where('automation_node_runs.created_at', $ascending ? '>' : '<', $cursor[0])
                            ->orWhere(fn (Builder $tie) => $tie
                                ->where('automation_node_runs.created_at', '=', $cursor[0])
                                ->where('automation_node_runs.id', $ascending ? '>' : '<', $cursor[1])
                            )
                    ))
                    ->with('run:id,subject_key')
                    ->limit(self::EXPORT_CHUNK)
                    ->get();

                if ($page->isEmpty()) {
                    break;
                }

                foreach ($page as $run) {
                    $row = $this->logRow($run, $labels);

                    $this->putCsv($handle, [
                        $row['created_at'],
                        // A step whose node is gone reads the same as one that
                        // still exists unless the file says so; the screen has
                        // a badge for it, the file had nothing.
                        $row['node_removed'] ? $row['node_label'].' '.__('statamic-automations::automations.activity.node_removed_suffix') : $row['node_label'],
                        $row['node_type'],
                        $row['status'],
                        $row['subject'] ?? '',
                        $row['duration_ms'] ?? '',
                        $row['error_message'] ?? '',
                    ]);

                    // A row with no `created_at` would make the cursor null
                    // and stop the file at that line without a word. Keeping
                    // the previous cursor means the next chunk starts where
                    // the last usable row was — one repeat beats a silent
                    // truncation.
                    if ($run->created_at !== null) {
                        $cursor = [$run->created_at, $run->id];
                    }
                }

                if ($page->count() < self::EXPORT_CHUNK) {
                    break;
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One CSV line, with the cells made safe to open.
     *
     * Two things `fputcsv` does not do on its own:
     *
     * **Formula injection.** A cell beginning with `=`, `+`, `-`, `@`, tab or
     * carriage return is executed by Excel and LibreOffice the moment the file
     * is opened, and the person opening it is the one with
     * `view automation runs`. `subject` is a mail address out of the trigger
     * context, which a stranger supplies through a form or a webhook — the
     * enrolment gate only trims and lowercases it. A leading apostrophe is the
     * spreadsheet's own "this is text" marker, consumed on import.
     *
     * **The escape character.** PHP's default `\` is not part of RFC 4180 and
     * mangles any value containing a backslash — which is every error message
     * carrying a class name. Passing an empty escape is also what PHP 8.4
     * requires to avoid a deprecation per row.
     *
     * @param  resource  $handle
     * @param  list<string|int|float|null>  $cells
     */
    protected function putCsv($handle, array $cells): void
    {
        fputcsv($handle, array_map(fn ($cell) => $this->csvValue($cell), $cells), escape: '');
    }

    /** A cell, as a string that opens as text rather than as a formula. */
    protected function csvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Who is inside the flow right now.
     *
     * There is no enrollment table and there is not meant to be one — a run
     * whose status is queued, running or waiting IS an enrollment, see
     * {@see RunStats}. So this is those runs, with the person they are about and
     * the step they are parked on.
     *
     * Runs with no `subject_key` (a scheduled sweep, a webhook that named
     * nobody) are legitimate and are not people. They are excluded from the rows
     * and counted in `meta.without_subject`, which the screen states — dropping
     * them silently would make the number here disagree with the funnel above it
     * for a reason nobody could see.
     *
     * The date range does not apply to this reading; see the comment in the
     * method. It is the one part of the activity view that is not about a
     * period.
     */
    public function subjects(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automation runs');

        $uuid = (string) $automationFlow->uuid;

        // **No time window here, on purpose.** This tab asks who is inside the
        // flow *now*, which is a question about the present and not about a
        // period. Applied, the window silently dropped the people it matters
        // most to see: somebody enrolled forty days ago and parked in a
        // sixty-day wait fell out of the list at the default "last 30 days" —
        // and out of `without_subject` with it, so not even the count on the
        // screen hinted that anybody was missing.
        $base = fn () => AutomationRun::query()
            ->where('automation_uuid', $uuid)
            ->where('is_test', false)
            ->whereIn('status', RunStats::IN_PROGRESS);

        $perPage = min(max((int) $request->query('perPage', self::PER_PAGE), 1), 100);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $base()
            ->whereNotNull('subject_key')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        /** @var Collection<int, AutomationRun> $runs */
        $runs = collect($paginator->items());
        $current = $this->currentNodes($runs->pluck('id')->all());
        $labels = $this->nodeLabels($automationFlow);
        $contacts = app(LeadHubAdapter::class)->contactUrls(
            $runs->pluck('subject_key')->filter()->unique()->values()->all()
        );

        return response()->json([
            'data' => $runs->map(function (AutomationRun $run) use ($current, $labels, $contacts) {
                $node = $current[$run->id] ?? null;
                $key = $node?->node_key;

                return [
                    'id' => (string) $run->id,
                    'subject' => $run->subject_key,
                    'contact_url' => $contacts[(string) $run->subject_key] ?? null,
                    'status' => $run->status,
                    'node_key' => $key,
                    'node_label' => $key === null
                        ? null
                        : ($labels[$key] ?? $key),
                    // A step that has since been deleted from the graph is still
                    // where this person is standing. Naming it as removed is the
                    // honest answer; hiding the row would lose the person.
                    'node_removed' => $key !== null && ! array_key_exists($key, $labels),
                    'enrolled_at' => optional($run->created_at)->toIso8601String(),
                    'show_url' => cp_route('statamic-automations.runs.show', $run->id),
                ];
            })->all(),
            'meta' => [
                'columns' => static::subjectColumns(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * The protocol query, before pagination.
     *
     * Single-table by design: `automation_uuid` and `is_test` sit on the node
     * run itself since `2026_08_15_000001`, so neither the automation nor the
     * test exclusion needs the parent. The parent is joined afterwards, for the
     * twenty-five rows a page has, to name the person.
     */
    private function logQuery(Automation $automation, Request $request, ActivityWindow $window): Builder
    {
        $query = AutomationNodeRun::query()
            ->where('automation_uuid', (string) $automation->uuid)
            ->where('is_test', false);

        $window->apply($query);

        // One step or several. The protocol's own filter sends a single handle;
        // the steps table's export action sends the selection, which is a list.
        // `whereIn` covers both, so there is one filter rather than two that
        // could come to disagree about what "no filter" means.
        if (($nodes = $this->nodeFilter($request)) !== []) {
            $query->whereIn('node_key', $nodes);
        }

        if (is_string($status = $request->query('status')) && $status !== '') {
            $query->where('status', $status);
        }

        // By time, then by id. The column header says "When" and that is what a
        // reader sorts by; ids agree with it on every row this engine writes
        // itself, and disagree on rows that arrived some other way (an import,
        // a backfill), which is exactly where ordering by id would quietly show
        // the wrong thing.
        //
        // The id is not decoration: `created_at` on this table is a
        // whole-second timestamp and a run writes several nodes inside one
        // second, so time alone is not a total order — and the export walks
        // this order by key, which needs one.
        $direction = $this->ascending($request) ? 'asc' : 'desc';

        return $query
            ->orderBy('automation_node_runs.created_at', $direction)
            ->orderBy('automation_node_runs.id', $direction);
    }

    private function ascending(Request $request): bool
    {
        return strtolower((string) $request->query('order', 'desc')) === 'asc';
    }

    /**
     * The `node` parameter, as a list either way.
     *
     * @return list<string>
     */
    private function nodeFilter(Request $request): array
    {
        $node = $request->query('node');

        if (is_string($node)) {
            return $node === '' ? [] : [$node];
        }

        if (! is_array($node)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($value) => is_string($value) ? $value : null, $node),
            fn (?string $value) => $value !== null && $value !== '',
        ));
    }

    /**
     * ── The steps table's actions ─────────────────────────────────────────
     *
     * Statamic's `Listing` ties its checkbox column to an action endpoint: no
     * endpoint, no checkboxes. The steps of an automation are counted rows
     * rather than records, so there is nothing to delete or publish — but there
     * is one thing a reader plainly wants to do with three of them at once, and
     * it is the thing the row menu already offers for one: export the protocol
     * of exactly those steps.
     *
     * The answer to the run is a CSV, not JSON. Statamic's action runner asks
     * for a blob and hands anything carrying a `Content-Disposition` straight to
     * the browser as a download, so a file is a legitimate result of an action.
     *
     * @return list<string>
     */
    private function knownNodeKeys(Automation $automation): array
    {
        $onCanvas = $automation->nodes->pluck('node_key')->all();

        // A step whose node has since been deleted still has runs, and the
        // table still lists it — so it is still exportable. The panel appends
        // exactly these rows under "No longer in the flow".
        $withRuns = AutomationNodeRun::query()
            ->where('automation_uuid', (string) $automation->uuid)
            ->where('is_test', false)
            ->distinct()
            ->pluck('node_key')
            ->all();

        return array_values(array_unique(array_map('strval', [...$onCanvas, ...$withRuns])));
    }

    public function stepActionList(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automation runs');

        $selections = $this->stepSelections($request);

        // A selection naming a step this automation has never had is a stale
        // table, and an action offered against it could only produce an empty
        // file that looks like a working one.
        if ($selections === [] || array_diff($selections, $this->knownNodeKeys($automationFlow)) !== []) {
            return response()->json([]);
        }

        $count = count($selections);

        return response()->json([[
            'handle' => 'export',
            'title' => $count === 1 ? __('Export this step') : __('Export :count steps', ['count' => $count]),
            'icon' => 'download',
            'component' => null,
            'runnable' => true,
            // No confirmation: an export changes nothing, and a dialog in front
            // of it would be the only one in the Control Panel that guards a
            // read.
            'confirm' => false,
            'dangerous' => false,
            'buttonText' => $count === 1 ? __('Export this step') : __('Export :count steps', ['count' => $count]),
            'confirmationText' => null,
            'warningText' => null,
            'dirtyWarningText' => null,
            'bypassesDirtyWarning' => true,
            'requiresElevatedSession' => false,
            'fields' => [],
            'values' => [],
            'meta' => [],
            // Echoed back, because the runner sends the action's own `context`
            // with the run and not the listing's. The window and the outcome
            // filter belong to the file the same way they belong to the table.
            'context' => $this->exportContext($request),
        ]]);
    }

    public function runStepAction(Request $request, Automation $automationFlow): StreamedResponse
    {
        $this->authorizeAction('view automation runs');

        $data = $request->validate([
            'action' => ['required', 'string', 'in:export'],
            'selections' => ['required', 'array', 'min:1'],
            'selections.*' => ['required', 'string'],
        ]);

        $context = $this->exportContext($request, 'context');

        // The export reads its parameters off the query string, because that is
        // what a GET download is. Rather than teaching it a second source, the
        // action builds the request that export already understands — one code
        // path for the file, whichever door it was asked for through.
        $proxy = Request::create('', 'GET', [
            ...$context,
            'node' => array_values(array_unique($data['selections'])),
        ]);

        return $this->export($proxy, $automationFlow);
    }

    /**
     * @return list<string>
     */
    private function stepSelections(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $request->input('selections', []);

        return array_values(array_filter(
            array_map(fn ($value) => is_string($value) ? $value : null, is_array($raw) ? $raw : []),
            fn (?string $value) => $value !== null && $value !== '',
        ));
    }

    /**
     * @return array{range: string, status: string, order: string}
     */
    private function exportContext(Request $request, string $key = 'context'): array
    {
        /** @var array<string, mixed> $raw */
        $raw = is_array($context = $request->input($key)) ? $context : [];

        // The window falls back to the panel's own default rather than to an
        // empty string: `ActivityWindow::make('')` is "everything there is", so
        // a missing context would silently export more than the table shows.
        $range = is_string($raw['range'] ?? null) && $raw['range'] !== '' ? $raw['range'] : ActivityWindow::DEFAULT;

        return [
            'range' => $range,
            'status' => is_string($raw['status'] ?? null) ? $raw['status'] : '',
            'order' => is_string($raw['order'] ?? null) ? $raw['order'] : 'desc',
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function logRow(AutomationNodeRun $run, array $labels): array
    {
        $key = (string) $run->node_key;

        return [
            'id' => (string) $run->id,
            'node_key' => $key,
            'node_label' => $labels[$key] ?? $key,
            // The graph is edited; the log is not. A row whose node has been
            // deleted since is not a defect and must not break the screen — it
            // is simply labelled by its key and marked.
            'node_removed' => ! array_key_exists($key, $labels),
            'node_type' => $run->node_type,
            'status' => $run->status,
            'subject' => $run->run?->subject_key,
            'duration_ms' => $run->duration_ms,
            'error_message' => $run->error_message,
            'created_at' => optional($run->created_at)->toIso8601String(),
            'show_url' => cp_route('statamic-automations.runs.show', $run->automation_run_id),
        ];
    }

    /**
     * node_key → label, for the steps the automation still has.
     *
     * Missing from this map means "no longer in the graph", which the rows
     * above report rather than hide.
     *
     * @return array<string, string>
     */
    private function nodeLabels(Automation $automation): array
    {
        return $automation->nodes
            ->mapWithKeys(fn ($node) => [
                (string) $node->node_key => (string) ($node->label ?: $node->type ?: $node->node_key),
            ])
            ->all();
    }

    /**
     * The newest node run of each of these runs, in ONE query.
     *
     * "Which step is this person on" is not a column anywhere — it is the last
     * row written for that run. Asked per person it is a query per row; asked
     * like this it is a query per page, whatever the page holds.
     *
     * @param  list<int>  $runIds
     * @return Collection<int, AutomationNodeRun>
     */
    private function currentNodes(array $runIds): Collection
    {
        if ($runIds === []) {
            return collect();
        }

        $latest = AutomationNodeRun::query()
            ->selectRaw('MAX(id)')
            ->whereIn('automation_run_id', $runIds)
            ->groupBy('automation_run_id');

        return AutomationNodeRun::query()
            ->whereIn('automation_run_id', $runIds)
            ->whereIn('id', $latest)
            ->get(['id', 'automation_run_id', 'node_key', 'node_type', 'status'])
            ->keyBy('automation_run_id');
    }

    /**
     * The protocol's columns, and the order the CSV writes them in.
     *
     * @return list<array<string, mixed>>
     */
    public static function logColumns(): array
    {
        return collect([
            Column::make('created_at')->label(__('When'))->sortable(true),
            Column::make('node_label')->label(__('Step'))->sortable(false),
            Column::make('node_type')->label(__('Kind'))->sortable(false),
            Column::make('status')->label(__('Status'))->sortable(false),
            Column::make('subject')->label(__('Person'))->sortable(false),
            Column::make('duration_ms')->label(__('Duration'))->sortable(false),
            Column::make('error_message')->label(__('Detail'))->sortable(false),
        ])->map(fn (Column $column) => $column->toArray())->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function subjectColumns(): array
    {
        return collect([
            Column::make('subject')->label(__('Person'))->sortable(false),
            Column::make('node_label')->label(__('Waiting at'))->sortable(false),
            Column::make('status')->label(__('Status'))->sortable(false),
            Column::make('enrolled_at')->label(__('Enrolled'))->sortable(false),
        ])->map(fn (Column $column) => $column->toArray())->all();
    }

    /**
     * The statuses a node run can end in, for the protocol's filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => '', 'label' => __('Any outcome')],
            ['value' => AutomationNodeRun::STATUS_SUCCESS, 'label' => __('Success')],
            ['value' => AutomationNodeRun::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => AutomationNodeRun::STATUS_SKIPPED, 'label' => __('Skipped')],
            ['value' => AutomationNodeRun::STATUS_STOPPED, 'label' => __('Stopped')],
            ['value' => AutomationNodeRun::STATUS_RUNNING, 'label' => __('Running')],
            ['value' => AutomationNodeRun::STATUS_PENDING, 'label' => __('Pending')],
        ];
    }
}
