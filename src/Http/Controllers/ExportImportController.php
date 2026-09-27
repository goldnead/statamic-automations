<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Export\AutomationExporter;
use Goldnead\StatamicAutomations\Export\AutomationFileSync;
use Goldnead\StatamicAutomations\Export\AutomationImporter;
use Goldnead\StatamicAutomations\Http\Resources\AutomationResource;
use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportImportController extends Controller
{
    public function export(Automation $automationFlow, AutomationExporter $exporter): Response
    {
        $this->authorizeAction('view automations');

        $payload = $exporter->toArray($automationFlow);
        $filename = "{$automationFlow->handle}.json";

        return response()
            ->json($payload, 200, [
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function import(Request $request, AutomationImporter $importer): JsonResponse
    {
        $this->authorizeAction('create automations');

        $payload = $request->input('payload');

        // Allow JSON file upload as `file` or raw JSON in `payload`.
        if ($payload === null && $request->hasFile('file')) {
            $contents = file_get_contents($request->file('file')->getRealPath());
            $payload = json_decode($contents ?: '', true);
        }

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Invalid payload. Provide either a JSON file (form upload key "file") or a "payload" object.',
            ], 422);
        }

        $strategy = (string) $request->input('handle_strategy', AutomationImporter::STRATEGY_AUTO);

        // Updating in place changes an existing automation, so it takes the
        // edit permission on top of the create permission an import needs.
        if ($strategy === AutomationImporter::STRATEGY_UPDATE) {
            $this->authorizeAction('edit automations');
        }

        try {
            $result = $importer->import($payload, [
                'handle_strategy' => $strategy,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new AutomationResource($result['automation']))->toArray($request),
            'meta' => [
                'updated' => $result['updated'],
                'warnings' => $result['warnings'],
                'missing_integrations' => $result['missing_integrations'],
                'missing_node_types' => $result['missing_node_types'],
            ],
        ], $result['updated'] ? 200 : 201);
    }

    public function syncToFile(Automation $automationFlow, AutomationFileSync $sync): JsonResponse
    {
        $this->authorizeAction('edit automations');

        $path = $sync->exportToFile($automationFlow);

        return response()->json([
            'ok' => true,
            'path' => $path,
        ]);
    }

    public function syncStatus(Automation $automationFlow, AutomationFileSync $sync): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json(['data' => $sync->syncStatus($automationFlow)]);
    }

    public function listFiles(AutomationFileSync $sync): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json(['data' => $sync->listFiles()]);
    }
}
