<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Http\Resources\AutomationRunResource;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunsController extends Controller
{
    public function index(Request $request): JsonResponse
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

        if ($triggerType = $request->input('trigger_type')) {
            $query->where('trigger_type', $triggerType);
        }

        if ($request->filled('is_test')) {
            $query->where('is_test', $request->boolean('is_test'));
        }

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        $runs = $query->paginate($request->integer('per_page', 25));

        return AutomationRunResource::collection($runs)
            ->response()
            ->setStatusCode(200);
    }

    public function show(AutomationRun $run): JsonResponse
    {
        $this->authorizeAction('view automation runs');

        $run->load(['automation:id,name,handle', 'nodeRuns']);

        return (new AutomationRunResource($run))
            ->response()
            ->setStatusCode(200);
    }

    public function retry(AutomationRun $run): JsonResponse
    {
        $this->authorizeAction('retry automation runs');

        $newRun = AutomationRun::create([
            'automation_id' => $run->automation_id,
            'trigger_node_key' => $run->trigger_node_key,
            'trigger_type' => $run->trigger_type,
            'status' => AutomationRun::STATUS_QUEUED,
            'context' => $run->context,
            'is_test' => $run->is_test,
        ]);

        RunAutomation::dispatch($newRun->id, $run->context ?? [], (bool) $run->is_test);

        return response()->json([
            'ok' => true,
            'run_id' => $newRun->id,
            'queued' => true,
        ]);
    }

    public function retryNodeRun(AutomationNodeRun $nodeRun): JsonResponse
    {
        $this->authorizeAction('retry automation runs');

        // For now we re-run the entire automation (Phase F task to support
        // partial-from-node retry). Document the behavior in the response.
        $run = $nodeRun->run;
        $newRun = AutomationRun::create([
            'automation_id' => $run->automation_id,
            'trigger_node_key' => $run->trigger_node_key,
            'trigger_type' => $run->trigger_type,
            'status' => AutomationRun::STATUS_QUEUED,
            'context' => $run->context,
            'is_test' => $run->is_test,
        ]);

        RunAutomation::dispatch($newRun->id, $run->context ?? [], (bool) $run->is_test);

        return response()->json([
            'ok' => true,
            'run_id' => $newRun->id,
            'queued' => true,
            'note' => 'Entire automation re-queued; partial retry from a specific node is on the roadmap.',
        ]);
    }
}
