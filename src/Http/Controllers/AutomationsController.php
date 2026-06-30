<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
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

        // Go through the storage driver so the listing works for both the
        // database and flat-file drivers. Search, the enabled filter and
        // pagination are applied in-memory on top of the driver collection.
        $items = app(AutomationRepository::class)->all();

        if ($search = $request->string('search')->toString()) {
            $needle = mb_strtolower($search);
            $items = $items->filter(fn ($a) => str_contains(mb_strtolower((string) $a->name), $needle)
                || str_contains(mb_strtolower((string) $a->handle), $needle));
        }

        if ($request->filled('enabled')) {
            $enabled = $request->boolean('enabled');
            $items = $items->filter(fn ($a) => (bool) $a->enabled === $enabled);
        }

        // Attach run counts keyed by automation uuid (set on every run in both
        // storage modes).
        $runCounts = AutomationRun::query()
            ->selectRaw('automation_uuid, count(*) as c')
            ->groupBy('automation_uuid')
            ->pluck('c', 'automation_uuid');

        $items = $items
            ->sortByDesc(fn ($a) => optional($a->updated_at)->timestamp ?? optional($a->last_run_at)->timestamp ?? 0)
            ->values()
            ->each(fn ($a) => $a->setAttribute('runs_count', (int) ($runCounts[$a->uuid] ?? 0)));

        $perPage = max(1, $request->integer('per_page', 25));
        $page = max(1, $request->integer('page', 1));

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return AutomationResource::collection($paginator)
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

        $automation = new Automation([
            'name' => $data['name'],
            'handle' => $data['handle'] ?? Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'enabled' => false,
            'created_by' => optional(auth()->user())->id,
        ]);

        $automation = app(AutomationRepository::class)
            ->save($automation, $data['nodes'] ?? [], $data['edges'] ?? []);

        app(\Goldnead\StatamicAutomations\Engine\VersionManager::class)
            ->snapshot($automation, 'Initial version');
        app(\Goldnead\StatamicAutomations\Support\AuditLogger::class)
            ->record('created', $automation, ['name' => $automation->name]);

        return (new AutomationResource($automation))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAutomationRequest $request, Automation $automation): JsonResponse
    {
        $data = $request->validated();

        // Snapshot the pre-edit graph so this change can be rolled back.
        app(\Goldnead\StatamicAutomations\Engine\VersionManager::class)->snapshot($automation);

        $automation->fill(array_filter([
            'name' => $data['name'] ?? null,
            'handle' => $data['handle'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : null,
        ], fn ($v) => $v !== null));
        $automation->version = (int) $automation->version + 1;

        $hasGraph = isset($data['nodes']) || isset($data['edges']);

        $automation = app(AutomationRepository::class)->save(
            $automation,
            $hasGraph ? ($data['nodes'] ?? []) : null,
            $hasGraph ? ($data['edges'] ?? []) : null,
        );

        app(\Goldnead\StatamicAutomations\Support\AuditLogger::class)
            ->record('updated', $automation, ['version' => $automation->version]);

        return (new AutomationResource($automation))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Automation $automation): JsonResponse
    {
        $this->authorizeAction('delete automations');

        app(\Goldnead\StatamicAutomations\Support\AuditLogger::class)
            ->record('deleted', $automation->exists ? $automation : null, ['name' => $automation->name, 'handle' => $automation->handle]);

        app(AutomationRepository::class)->delete($automation);

        return response()->json(['ok' => true]);
    }

    public function duplicate(Automation $automation): JsonResponse
    {
        $this->authorizeAction('create automations');

        $clone = new Automation([
            'name' => $automation->name . ' (copy)',
            'handle' => $automation->handle . '-copy-' . Str::lower(Str::random(4)),
            'description' => $automation->description,
            'enabled' => false,
            'created_by' => optional(auth()->user())->id,
        ]);

        $nodes = $automation->nodes->map(fn ($node) => [
            'node_key' => $node->node_key,
            'type' => $node->type,
            'label' => $node->label,
            'position_x' => $node->position_x,
            'position_y' => $node->position_y,
            'config' => $node->config,
            'disabled' => $node->disabled,
        ])->all();

        $edges = $automation->edges->map(fn ($edge) => [
            'from_node_key' => $edge->from_node_key,
            'from_output' => $edge->from_output,
            'to_node_key' => $edge->to_node_key,
            'to_input' => $edge->to_input,
        ])->all();

        $clone = app(AutomationRepository::class)->save($clone, $nodes, $edges);

        return (new AutomationResource($clone))
            ->response()
            ->setStatusCode(201);
    }

    public function validateAutomation(Automation $automation, FlowValidator $validator): JsonResponse
    {
        $this->authorizeAction('view automations');

        $automation->loadMissing(['nodes', 'edges']);
        $issues = $validator->validate($automation);

        return response()->json([
            'valid' => empty(array_filter($issues, fn ($i) => ($i['level'] ?? 'error') === 'error')),
            'issues' => $issues,
        ]);
    }

    public function enable(Automation $automation, FlowValidator $validator): JsonResponse
    {
        $this->authorizeAction('enable automations');

        $automation->loadMissing(['nodes', 'edges']);
        $issues = $validator->validate($automation);
        $errors = array_filter($issues, fn ($i) => ($i['level'] ?? 'error') === 'error');

        if (! empty($errors)) {
            return response()->json([
                'ok' => false,
                'message' => 'Automation cannot be enabled while it has validation errors.',
                'issues' => $issues,
            ], 422);
        }

        $automation->enabled = true;
        app(AutomationRepository::class)->save($automation);

        app(\Goldnead\StatamicAutomations\Support\AuditLogger::class)->record('enabled', $automation);

        return response()->json(['ok' => true, 'enabled' => true]);
    }

    public function disable(Automation $automation): JsonResponse
    {
        $this->authorizeAction('enable automations');

        $automation->enabled = false;
        app(AutomationRepository::class)->save($automation);

        app(\Goldnead\StatamicAutomations\Support\AuditLogger::class)->record('disabled', $automation);

        return response()->json(['ok' => true, 'enabled' => false]);
    }

    public function test(Request $request, Automation $automation, WorkflowRunner $runner): JsonResponse
    {
        $this->authorizeAction('run automation tests');

        $automation->loadMissing(['nodes', 'edges']);
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
     * Execute a single node in isolation against a supplied (or sample)
     * context, in test mode, without persisting a run. Powers the
     * builder's "test this node" affordance.
     */
    public function testNode(
        Request $request,
        Automation $automation,
        \Goldnead\StatamicAutomations\Engine\NodeExecutor $executor,
    ): JsonResponse {
        $this->authorizeAction('run automation tests');

        $nodeKey = (string) $request->input('node_key');
        $node = $automation->nodes()->where('node_key', $nodeKey)->first();

        if ($node === null) {
            return response()->json(['ok' => false, 'message' => "Node '{$nodeKey}' not found."], 404);
        }

        $context = AutomationContext::make((array) $request->input('context', []), testMode: true);
        $result = $executor->execute($node, $context);

        return response()->json([
            'ok' => ! $result->isFailed(),
            'node_key' => $nodeKey,
            'node_type' => $node->type,
            'status' => $result->status,
            'output' => $result->output,
            'output_handle' => $result->outputHandle,
            'error_message' => $result->error,
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
