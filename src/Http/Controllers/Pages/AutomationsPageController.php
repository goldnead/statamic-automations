<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
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

        $rows = app(AutomationRepository::class)->all()
            ->sortByDesc(fn (Automation $a) => optional($a->updated_at)->timestamp ?? optional($a->last_run_at)->timestamp ?? 0)
            ->map(fn (Automation $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'handle' => $a->handle,
                'enabled' => (bool) $a->enabled,
                'runs_count' => (int) ($runCounts[$a->uuid] ?? 0),
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
                Column::make('last_run_at')->label(__('Last run')),
                Column::make('updated_at')->label(__('Updated')),
            ])->map->toArray()->all(),
            'createUrl' => cp_route('statamic-automations.automations.create'),
            // The API root (…/api). Index.vue appends '/automations/{id}/…'
            // itself, so this must NOT be the …/api/automations listing route —
            // that doubled the segment and 404ed every row action.
            'apiBase' => cp_route('statamic-automations.api.index'),
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

    public function edit(Request $request, Automation $automation)
    {
        $this->authorizeAction('edit automations');

        $automation->loadMissing(['nodes', 'edges']);

        return Inertia::render('statamic-automations::Automations/Edit', [
            'mode' => 'edit',
            'title' => $automation->name,
            'automation' => $this->automationPayload($automation),
            'library' => $this->nodeLibraryPayload(),
            'apiBase' => cp_route('statamic-automations.api.index'),
            'indexUrl' => cp_route('statamic-automations.automations.index'),
            'runsUrl' => cp_route('statamic-automations.runs.index') . '?automation_id=' . $automation->id,
            'canEdit' => $this->userCan('edit automations'),
            'canEnable' => $this->userCan('enable automations'),
            'canDelete' => $this->userCan('delete automations'),
            'canTest' => $this->userCan('run automation tests'),
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
