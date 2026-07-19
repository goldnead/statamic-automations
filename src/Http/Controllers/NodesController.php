<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\OptionSourceRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodesController extends Controller
{
    /**
     * Return all registered nodes (triggers / logic / actions) with
     * full schema metadata. The Vue Flow canvas uses this to render
     * the Node Library.
     */
    public function index(NodeRegistry $registry, IntegrationDetector $detector): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'data' => [
                'triggers' => $registry->byKind('trigger'),
                'logic' => $registry->byKind('logic'),
                'actions' => $registry->byKind('action'),
            ],
            'meta' => [
                'integrations' => $detector->snapshot(),
            ],
        ]);
    }

    public function triggers(TriggerRegistry $triggers, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        $data = array_map(
            fn (string $handle) => $registry->describe($handle),
            array_keys($triggers->all()),
        );

        return response()->json(['data' => $data]);
    }

    public function actions(ActionRegistry $actions, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        $data = array_map(
            fn (string $handle) => $registry->describe($handle),
            array_keys($actions->all()),
        );

        return response()->json(['data' => $data]);
    }

    public function describe(string $handle, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        if (! $registry->has($handle)) {
            return response()->json(['message' => "Node '{$handle}' not found."], 404);
        }

        return response()->json(['data' => $registry->describe($handle)]);
    }

    /**
     * Returns the output schema for a particular trigger handle so the
     * data picker can present available variables.
     */
    public function contextSchema(string $handle, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        $description = $registry->describe($handle);

        if (empty($description) || ($description['kind'] ?? null) !== 'trigger') {
            return response()->json(['message' => "Trigger '{$handle}' not found."], 404);
        }

        return response()->json([
            'data' => $description['output_schema'] ?? [],
        ]);
    }

    /**
     * Resolve dynamic option sources (e.g. leadhub.statuses, statamic.collections,
     * or any third-party source) to a flat list of options for the config form.
     *
     * Every source — built-in Statamic sources included — is resolved through
     * the {@see OptionSourceRegistry}. Third parties register their own via
     * `Automations::registerOptionSource(handle, resolver)`. Statamic-native
     * sources are registered under both the bare handle (`collections`) and the
     * historical `statamic.`-prefixed spelling (`statamic.collections`), so
     * existing node schemas keep working and the frontend may request either.
     * An unknown source resolves to an empty list, never a fatal.
     */
    public function options(string $source, Request $request, OptionSourceRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json(['data' => $registry->resolve($source, $request)]);
    }
}
