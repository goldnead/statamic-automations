<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

class VersionsController extends Controller
{
    public function index(Automation $automationFlow, VersionManager $versions): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'current_version' => $automationFlow->version,
            'versions' => $versions->versions($automationFlow),
        ]);
    }

    public function revert(
        Automation $automationFlow,
        int $timestamp,
        VersionManager $versions,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeAction('edit automations');

        try {
            $reverted = $versions->revert($automationFlow, $timestamp);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 404);
        }

        $audit->record('reverted', $reverted, ['to_revision' => $timestamp]);

        return response()->json([
            'ok' => true,
            'version' => $reverted->version,
        ]);
    }
}
