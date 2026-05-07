<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Http\Requests\StoreAutomationRequest;
use Goldnead\StatamicAutomations\Http\Requests\UpdateAutomationRequest;
use Goldnead\StatamicAutomations\Http\Resources\AutomationResource;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction('view automations');

        $query = Automation::query()
            ->withCount('runs')
            ->latest('updated_at');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%");
            });
        }

        if ($request->filled('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        $automations = $query->paginate($request->integer('per_page', 25));

        return AutomationResource::collection($automations)
            ->response()
            ->setStatusCode(200);
    }

    public function show(Automation $automation): JsonResponse
    {
        $this->authorizeAction('view automations');

        $automation->load(['nodes', 'edges']);

        return (new AutomationResource($automation))
            ->response()
            ->setStatusCode(200);
    }

    public function store(StoreAutomationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $automation = DB::transaction(function () use ($data) {
            $automation = Automation::create([
                'name' => $data['name'],
                'handle' => $data['handle'] ?? Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
                'description' => $data['description'] ?? null,
                'enabled' => false,
                'created_by' => optional(auth()->user())->id,
            ]);

            $this->syncGraph($automation, $data['nodes'] ?? [], $data['edges'] ?? []);

            return $automation;
        });

        return (new AutomationResource($automation->fresh(['nodes', 'edges'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAutomationRequest $request, Automation $automation): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($automation, $data) {
            $automation->fill(array_filter([
                'name' => $data['name'] ?? null,
                'handle' => $data['handle'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
            ], fn ($v) => $v !== null));
            $automation->save();

            if (isset($data['nodes']) || isset($data['edges'])) {
                $this->syncGraph(
                    $automation,
                    $data['nodes'] ?? [],
                    $data['edges'] ?? [],
                );
            }
        });

        return (new AutomationResource($automation->fresh(['nodes', 'edges'])))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Automation $automation): JsonResponse
    {
        $this->authorizeAction('delete automations');

        $automation->delete();

        return response()->json(['ok' => true]);
    }

    public function duplicate(Automation $automation): JsonResponse
    {
        $this->authorizeAction('create automations');

        $clone = DB::transaction(function () use ($automation) {
            $clone = Automation::create([
                'name' => $automation->name . ' (copy)',
                'handle' => $automation->handle . '-copy-' . Str::lower(Str::random(4)),
                'description' => $automation->description,
                'enabled' => false,
                'created_by' => optional(auth()->user())->id,
            ]);

            foreach ($automation->nodes as $node) {
                AutomationNode::create([
                    'automation_id' => $clone->id,
                    'node_key' => $node->node_key,
                    'type' => $node->type,
                    'label' => $node->label,
                    'position_x' => $node->position_x,
                    'position_y' => $node->position_y,
                    'config' => $node->config,
                    'disabled' => $node->disabled,
                ]);
            }

            foreach ($automation->edges as $edge) {
                AutomationEdge::create([
                    'automation_id' => $clone->id,
                    'from_node_key' => $edge->from_node_key,
                    'from_output' => $edge->from_output,
                    'to_node_key' => $edge->to_node_key,
                    'to_input' => $edge->to_input,
                ]);
            }

            return $clone;
        });

        return (new AutomationResource($clone->fresh(['nodes', 'edges'])))
            ->response()
            ->setStatusCode(201);
    }

    public function validateAutomation(Automation $automation, FlowValidator $validator): JsonResponse
    {
        $this->authorizeAction('view automations');

        $automation->load(['nodes', 'edges']);
        $issues = $validator->validate($automation);

        return response()->json([
            'valid' => empty(array_filter($issues, fn ($i) => ($i['level'] ?? 'error') === 'error')),
            'issues' => $issues,
        ]);
    }

    public function enable(Automation $automation, FlowValidator $validator): JsonResponse
    {
        $this->authorizeAction('enable automations');

        $automation->load(['nodes', 'edges']);
        $issues = $validator->validate($automation);
        $errors = array_filter($issues, fn ($i) => ($i['level'] ?? 'error') === 'error');

        if (! empty($errors)) {
            return response()->json([
                'ok' => false,
                'message' => 'Automation cannot be enabled while it has validation errors.',
                'issues' => $issues,
            ], 422);
        }

        $automation->forceFill(['enabled' => true])->save();

        return response()->json(['ok' => true, 'enabled' => true]);
    }

    public function disable(Automation $automation): JsonResponse
    {
        $this->authorizeAction('enable automations');

        $automation->forceFill(['enabled' => false])->save();

        return response()->json(['ok' => true, 'enabled' => false]);
    }

    public function test(Request $request, Automation $automation, WorkflowRunner $runner): JsonResponse
    {
        $this->authorizeAction('run automation tests');

        $automation->load(['nodes', 'edges']);
        $contextData = (array) $request->input('context', []);

        $context = AutomationContext::make($contextData, testMode: true);
        $triggerNode = $automation->nodes->first(
            fn ($n) => app(\Goldnead\StatamicAutomations\Registries\NodeRegistry::class)
                ->kind($n->type) === 'trigger',
        );

        $run = $runner->createRun($automation, $context, $triggerNode);
        $finalRun = $runner->execute($run, $context);

        return response()->json([
            'run_id' => $finalRun->id,
            'status' => $finalRun->status,
            'duration_ms' => $finalRun->duration_ms,
            'node_runs' => $finalRun->nodeRuns()->orderBy('id')->get()->map(fn ($r) => [
                'node_key' => $r->node_key,
                'node_type' => $r->node_type,
                'status' => $r->status,
                'input' => $r->input,
                'output' => $r->output,
                'error_message' => $r->error_message,
                'duration_ms' => $r->duration_ms,
            ]),
            'error_message' => $finalRun->error_message,
        ]);
    }

    /**
     * Replace an automation's nodes and edges atomically.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    protected function syncGraph(Automation $automation, array $nodes, array $edges): void
    {
        $automation->edges()->delete();
        $automation->nodes()->delete();

        foreach ($nodes as $node) {
            AutomationNode::create([
                'automation_id' => $automation->id,
                'node_key' => $node['node_key'],
                'type' => $node['type'],
                'label' => $node['label'] ?? null,
                'position_x' => (int) ($node['position_x'] ?? 0),
                'position_y' => (int) ($node['position_y'] ?? 0),
                'config' => $node['config'] ?? [],
                'disabled' => (bool) ($node['disabled'] ?? false),
            ]);
        }

        foreach ($edges as $edge) {
            AutomationEdge::create([
                'automation_id' => $automation->id,
                'from_node_key' => $edge['from_node_key'],
                'from_output' => $edge['from_output'] ?? 'default',
                'to_node_key' => $edge['to_node_key'],
                'to_input' => $edge['to_input'] ?? 'default',
            ]);
        }
    }
}
