<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationVersion;
use Goldnead\StatamicAutomations\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

class VersionsController extends Controller
{
    public function index(Automation $automation): JsonResponse
    {
        $this->authorizeAction('view automations');

        $versions = AutomationVersion::where('automation_id', $automation->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AutomationVersion $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'label' => $v->label,
                'node_count' => count($v->snapshot['nodes'] ?? []),
                'created_by' => $v->created_by,
                'created_at' => optional($v->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'current_version' => $automation->version,
            'versions' => $versions,
        ]);
    }

    public function revert(
        Automation $automation,
        AutomationVersion $version,
        VersionManager $versions,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeAction('edit automations');

        if ($version->automation_id !== $automation->id) {
            return response()->json(['ok' => false, 'message' => 'Version does not belong to this automation.'], 404);
        }

        $reverted = $versions->revert($automation, $version);
        $audit->record('reverted', $reverted, ['to_version' => $version->version]);

        return response()->json([
            'ok' => true,
            'version' => $reverted->version,
        ]);
    }
}
