<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Http\Controllers\ActivityController;
use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Sequence\MailListProjection;
use Goldnead\StatamicAutomations\Sequence\MailSteps;
use Goldnead\StatamicAutomations\Support\ActivityWindow;
use Goldnead\StatamicAutomations\Support\RunStats;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * Renders the Inertia pages for the Automations list and builder.
 *
 * The actual data mutations (create, update, validate, test, ...) live
 * on AutomationsController and are exposed under /cp/automations/api/*
 * — the Vue builder hits those via axios for canvas operations.
 */
class AutomationsPageController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction('view automations');

        // Run counts keyed by automation uuid (set on every run in both
        // storage modes), so the list works for database and flat-file alike.
        $runCounts = AutomationRun::query()
            ->selectRaw('automation_uuid, count(*) as c')
            ->groupBy('automation_uuid')
            ->pluck('c', 'automation_uuid');

        $automations = app(AutomationRepository::class)->all();

        // The funnel, for every row, in ONE query — see Support\RunStats. Test
        // runs are left out of it on purpose: an editor pressing "test" is not
        // a person going through the flow, and `runs_count` above still counts
        // everything, so nothing an existing screen shows changes.
        $stats = app(RunStats::class)->forAutomations(
            $automations->pluck('uuid')->filter()->map('strval')->all(),
        );

        $rows = $automations
            ->sortByDesc(fn (Automation $a) => optional($a->updated_at)->timestamp ?? optional($a->last_run_at)->timestamp ?? 0)
            ->map(fn (Automation $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'handle' => $a->handle,
                'enabled' => (bool) $a->enabled,
                'runs_count' => (int) ($runCounts[$a->uuid] ?? 0),
                'enrolled' => (int) ($stats[$a->uuid]['enrolled'] ?? 0),
                'in_progress' => (int) ($stats[$a->uuid]['in_progress'] ?? 0),
                'completed' => (int) ($stats[$a->uuid]['completed'] ?? 0),
                'exited' => (int) ($stats[$a->uuid]['exited'] ?? 0),
                'failed' => (int) ($stats[$a->uuid]['failed'] ?? 0),
                'last_run_at' => optional($a->last_run_at)->toIso8601String(),
                'updated_at' => optional($a->updated_at)->toIso8601String(),
                'edit_url' => cp_route('statamic-automations.automations.edit', $a->id),
                'can_edit' => $this->userCan('edit automations'),
                'can_delete' => $this->userCan('delete automations'),
                'can_enable' => $this->userCan('enable automations'),
            ])
            ->values();

        return Inertia::render('statamic-automations::Automations/Index', [
            'title' => __('Automations'),
            'rows' => $rows,
            'columns' => collect([
                Column::make('name')->label(__('Name')),
                Column::make('enabled')->label(__('Status')),
                Column::make('runs_count')->label(__('Runs')),
                // The three numbers that make a flow readable at a glance:
                // how many are in it, how many got through, how many left.
                Column::make('in_progress')->label(__('In progress')),
                Column::make('completed')->label(__('Completed')),
                Column::make('exited')->label(__('Exited')),
                Column::make('last_run_at')->label(__('Last run')),
                Column::make('updated_at')->label(__('Updated')),
            ])->map->toArray()->all(),
            'createUrl' => cp_route('statamic-automations.automations.create'),
            // The templates screen, resolved from its own route name. The empty
            // state used to derive it with `createUrl.replace('/create', '/templates')`,
            // which produced /cp/automations/automations/templates — the create
            // route carries the doubled `automations/automations` segment, the
            // templates route does not. That link 404ed on every fresh install.
            'templatesUrl' => cp_route('statamic-automations.templates.index'),
            // The API root (…/api). Index.vue appends '/automations/{id}/…'
            // itself, so this must NOT be the …/api/automations listing route —
            // that doubled the segment and 404ed every row action.
            'apiBase' => cp_route('statamic-automations.api.index'),
            // The empty state advertises how many templates are on offer. It
            // used to say "eight" in hardcoded prose while the registry shipped
            // eleven; counting the registry is the only version that cannot go
            // stale when a template is added.
            'templateCount' => count(app(TemplateRegistry::class)->all()),
            'canCreate' => $this->userCan('create automations'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAction('create automations');

        return Inertia::render('statamic-automations::Automations/Edit', [
            'mode' => 'create',
            'title' => __('New Automation'),
            'automation' => $this->blankAutomationPayload(),
            'library' => $this->nodeLibraryPayload(),
            'apiBase' => cp_route('statamic-automations.api.index'),
            'indexUrl' => cp_route('statamic-automations.automations.index'),
        ]);
    }

    public function edit(Request $request, Automation $automationFlow)
    {
        $this->authorizeAction('edit automations');

        $automationFlow->loadMissing(['nodes', 'edges']);

        return Inertia::render('statamic-automations::Automations/Edit', [
            'mode' => 'edit',
            'title' => $automationFlow->name,
            'automation' => $this->automationPayload($automationFlow),
            'library' => $this->nodeLibraryPayload(),
            'apiBase' => cp_route('statamic-automations.api.index'),
            'indexUrl' => cp_route('statamic-automations.automations.index'),
            'runsUrl' => cp_route('statamic-automations.runs.index').'?automation_id='.$automationFlow->id,
            // The same automation, read as the list of mails it sends. Always
            // present: the list is shown for every automation and only its
            // EDITING is bound to the flow being a straight line.
            'mailList' => app(MailListProjection::class)->forAutomation($automationFlow),
            'mailListUrl' => cp_route('statamic-automations.api.automations.mail-list', $automationFlow->id),
            // Which node types may be added AS a mail. Read off the registry
            // rather than off the mails already on the canvas: an automation
            // that has no mail yet is exactly the one that needs to add its
            // first, and it is the only screen that could not answer the
            // question from its own rows. See Sequence\MailSteps.
            'mailTypes' => $this->mailTypesPayload(),
            'stats' => app(RunStats::class)->forAutomation((string) $automationFlow->uuid),
            // The third reading of the same automation: what it has actually
            // been doing. The numbers travel as a prop rather than being
            // fetched, because the canvas paints them on its cards the moment
            // the page opens — an empty first frame followed by numbers
            // appearing is worse than a prop. Everything that comes AFTER a
            // click (the protocol, the people, another window) is fetched from
            // ActivityController; re-rendering this page, which loads the graph,
            // the node registry and the mail projection, to turn a table page
            // would cost more than the table.
            'activity' => $this->activityPayload($automationFlow),
            'canEdit' => $this->userCan('edit automations'),
            'canEnable' => $this->userCan('enable automations'),
            'canDelete' => $this->userCan('delete automations'),
            'canTest' => $this->userCan('run automation tests'),
        ]);
    }

    /**
     * Everything the activity view needs to render its first frame, or null.
     *
     * Null when the user may not read runs. The view is a reading of
     * `automation_runs` and `automation_node_runs`, so it is gated on
     * `view automation runs` — the same permission the run listing and every
     * endpoint behind this view ask for — and not on `edit automations`, which
     * is what got them onto this page.
     *
     * @return array<string, mixed>|null
     */
    protected function activityPayload(Automation $automation): ?array
    {
        if (! $this->userCan('view automation runs')) {
            return null;
        }

        $window = ActivityWindow::default();

        return array_merge(ActivityController::payload($automation, $window), [
            'ranges' => ActivityWindow::options(),
            'statusOptions' => ActivityController::statusOptions(),
            'logColumns' => ActivityController::logColumns(),
            'subjectColumns' => ActivityController::subjectColumns(),
            'overviewUrl' => cp_route('statamic-automations.api.automations.activity', $automation->id),
            'logUrl' => cp_route('statamic-automations.api.automations.activity.node-runs', $automation->id),
            'subjectsUrl' => cp_route('statamic-automations.api.automations.activity.subjects', $automation->id),
            'exportUrl' => cp_route('statamic-automations.api.automations.activity.export', $automation->id),
            // What the steps table hands `Listing` as its `action-url`. Without
            // it the table has no checkbox column at all — Statamic ties the
            // one to the other.
            'stepActionsUrl' => cp_route('statamic-automations.api.automations.activity.step-actions', $automation->id),
        ]);
    }

    protected function blankAutomationPayload(): array
    {
        return [
            'id' => null,
            'name' => '',
            'handle' => '',
            'description' => '',
            'enabled' => false,
            'nodes' => [],
            'edges' => [],
        ];
    }

    protected function automationPayload(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'handle' => $automation->handle,
            'description' => $automation->description,
            'enabled' => (bool) $automation->enabled,
            'nodes' => $automation->nodes->map(fn ($n) => [
                'node_key' => $n->node_key,
                'type' => $n->type,
                'label' => $n->label,
                'position_x' => (int) $n->position_x,
                'position_y' => (int) $n->position_y,
                'config' => $n->config ?? [],
                'disabled' => (bool) $n->disabled,
            ])->values()->all(),
            'edges' => $automation->edges->map(fn ($e) => [
                'from_node_key' => $e->from_node_key,
                'from_output' => $e->from_output,
                'to_node_key' => $e->to_node_key,
                'to_input' => $e->to_input,
            ])->values()->all(),
        ];
    }

    /**
     * The node types the mail list may insert, as `{handle, label}`.
     *
     * @return list<array{handle: string, label: string}>
     */
    protected function mailTypesPayload(): array
    {
        $mails = app(MailSteps::class);

        return array_values(array_map(
            fn (array $node) => [
                'handle' => (string) $node['handle'],
                'label' => is_string($node['label'] ?? null) && $node['label'] !== ''
                    ? $node['label']
                    : (string) $node['handle'],
            ],
            array_filter(
                app(NodeRegistry::class)->all(),
                fn (array $node) => isset($node['handle']) && $mails->isMailHandle((string) $node['handle']),
            ),
        ));
    }

    protected function nodeLibraryPayload(): array
    {
        $registry = app(NodeRegistry::class);
        $items = $registry->all();

        return [
            'triggers' => array_values(array_filter($items, fn ($i) => $i['kind'] === 'trigger')),
            'logic' => array_values(array_filter($items, fn ($i) => $i['kind'] === 'logic')),
            'actions' => array_values(array_filter($items, fn ($i) => $i['kind'] === 'action')),
        ];
    }

    protected function userCan(string $permission): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        if (method_exists($user, 'can')) {
            return (bool) $user->can($permission);
        }

        return true;
    }
}
