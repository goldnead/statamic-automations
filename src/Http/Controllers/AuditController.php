<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Models\AutomationAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction('view automations');

        $query = AutomationAuditLog::query()
            ->with('automation:id,name,handle')
            ->latest('id');

        if ($request->filled('automation_id')) {
            $query->where('automation_id', $request->integer('automation_id'));
        }

        if ($action = $request->string('action')->toString()) {
            $query->where('action', $action);
        }

        $logs = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AutomationAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'automation' => $log->automation ? [
                    'id' => $log->automation->id,
                    'name' => $log->automation->name,
                    'handle' => $log->automation->handle,
                ] : null,
                'user_label' => $log->user_label,
                'meta' => $log->meta,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
