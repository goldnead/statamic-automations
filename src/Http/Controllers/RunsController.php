<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Http\Resources\AutomationRunResource;
use Goldnead\StatamicAutomations\Jobs\RetryFromNode;
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

    /**
     * Retry from a specific node — re-execute just $nodeRun's node and
     * everything downstream of it. The trigger and earlier nodes are
     * not re-run; their original outputs are reused as context seed.
     */
    public function retryNodeRun(AutomationNodeRun $nodeRun): JsonResponse
    {
        $this->authorizeAction('retry automation runs');

        $run = $nodeRun->run;

        $newRun = AutomationRun::create([
            'automation_id' => $run->automation_id,
            'trigger_node_key' => $run->trigger_node_key,
            'trigger_type' => $run->trigger_type,
            'status' => AutomationRun::STATUS_QUEUED,
            'context' => $run->context,
            'is_test' => $run->is_test,
        ]);

        // Seed the new run's context with the original run's context plus
        // every node output that already finished successfully *before*
        // the retry point. This keeps tokens like {{ nodes.previous.id }}
        // working without re-executing those nodes.
        $context = is_array($run->context) ? $run->context : [];
        $context['nodes'] = $context['nodes'] ?? [];

        $previousOutputs = $run->nodeRuns()
            ->where('id', '<', $nodeRun->id)
            ->where('status', AutomationNodeRun::STATUS_SUCCESS)
            ->orderBy('id')
            ->get();

        foreach ($previousOutputs as $previous) {
            if (! empty($previous->node_key)) {
                $context['nodes'][$previous->node_key] = $previous->output ?? [];
            }
        }

        RetryFromNode::dispatch(
            $newRun->id,
            $nodeRun->node_key,
            $context,
            (bool) $run->is_test,
        );

        return response()->json([
            'ok' => true,
            'run_id' => $newRun->id,
            'queued' => true,
            'resuming_from' => $nodeRun->node_key,
            'replayed_node_outputs' => $previousOutputs->pluck('node_key')->all(),
        ]);
    }
}
