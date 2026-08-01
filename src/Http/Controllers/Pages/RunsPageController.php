<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

class RunsPageController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction('view automation runs');

        $query = AutomationRun::query()
            ->with('automation:id,name,handle')
            ->latest('id');

        if ($automationId = $request->input('automation_id')) {
            $query->where('automation_id', $automationId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->filled('is_test')) {
            $query->where('is_test', $request->boolean('is_test'));
        }

        // Resolve names once (uuid → name) so the listing works for both
        // database and flat-file definitions without N lookups.
        $names = app(\Goldnead\StatamicAutomations\Contracts\AutomationRepository::class)
            ->all()->keyBy('uuid')->map->name;

        $runs = $query->limit(200)->get()->map(fn (AutomationRun $r) => [
            'id' => $r->id,
            'automation_id' => $r->automation_id,
            'automation_name' => $names[$r->automation_uuid] ?? optional($r->automation)->name,
            'status' => $r->status,
            'trigger_type' => $r->trigger_type,
            'is_test' => (bool) $r->is_test,
            'duration_ms' => $r->duration_ms,
            'started_at' => optional($r->started_at)->toIso8601String(),
            'finished_at' => optional($r->finished_at)->toIso8601String(),
            'created_at' => optional($r->created_at)->toIso8601String(),
            'show_url' => cp_route('statamic-automations.runs.show', $r->id),
        ])->values();

        return Inertia::render('statamic-automations::Runs/Index', [
            'title' => __('Automation Runs'),
            'rows' => $runs,
            'columns' => collect([
                Column::make('id')->label(__('Run')),
                Column::make('automation_name')->label(__('Automation')),
                Column::make('status')->label(__('Status')),
                Column::make('trigger_type')->label(__('Trigger')),
                Column::make('duration_ms')->label(__('Duration')),
                Column::make('started_at')->label(__('Started')),
            ])->map->toArray()->all(),
            'filters' => [
                'automation_id' => $request->input('automation_id'),
                'status' => $request->input('status'),
                'is_test' => $request->input('is_test'),
            ],
            'statusOptions' => [
                ['value' => '', 'label' => __('All statuses')],
                ['value' => 'success', 'label' => __('Success')],
                ['value' => 'failed', 'label' => __('Failed')],
                ['value' => 'stopped', 'label' => __('Stopped')],
                ['value' => 'running', 'label' => __('Running')],
                ['value' => 'queued', 'label' => __('Queued')],
                ['value' => 'waiting', 'label' => __('Waiting')],
            ],
            // The empty state used a relative `href="../automations"`, which
            // only resolved because of where the browser happened to be. Any
            // trailing slash or extra nesting on this route would have silently
            // pointed it somewhere else.
            'automationsUrl' => cp_route('statamic-automations.automations.index'),
            'canRetry' => $this->userCan('retry automation runs'),
        ]);
    }

    public function show(AutomationRun $run)
    {
        $this->authorizeAction('view automation runs');

        $run->load(['nodeRuns']);
        $automation = $run->resolveAutomation();

        return Inertia::render('statamic-automations::Runs/Show', [
            'title' => __('Run #:id', ['id' => $run->id]),
            'run' => [
                'id' => $run->id,
                'uuid' => $run->uuid,
                'status' => $run->status,
                'trigger_type' => $run->trigger_type,
                'trigger_node_key' => $run->trigger_node_key,
                'is_test' => (bool) $run->is_test,
                'duration_ms' => $run->duration_ms,
                'started_at' => optional($run->started_at)->toIso8601String(),
                'finished_at' => optional($run->finished_at)->toIso8601String(),
                'error_message' => $run->error_message,
                'context' => $run->context,
                'automation' => $automation ? [
                    'id' => $automation->id,
                    'name' => $automation->name,
                    'handle' => $automation->handle,
                    'edit_url' => cp_route('statamic-automations.automations.edit', $automation->id),
                ] : null,
                'node_runs' => $run->nodeRuns->map(fn ($n) => [
                    'id' => $n->id,
                    'node_key' => $n->node_key,
                    'node_type' => $n->node_type,
                    'status' => $n->status,
                    'duration_ms' => $n->duration_ms,
                    'started_at' => optional($n->started_at)->toIso8601String(),
                    'finished_at' => optional($n->finished_at)->toIso8601String(),
                    'input' => $n->input,
                    'output' => $n->output,
                    'error_message' => $n->error_message,
                ])->values()->all(),
            ],
            'indexUrl' => cp_route('statamic-automations.runs.index'),
            'retryUrl' => cp_route('statamic-automations.api.runs.retry', $run->id),
            'canRetry' => $this->userCan('retry automation runs'),
        ]);
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
