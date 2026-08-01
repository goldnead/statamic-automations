<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Http\Resources\AutomationResource;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TemplatesController extends Controller
{
    public function index(TemplateRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'data' => $registry->all(),
        ]);
    }

    public function install(string $handle, TemplateRegistry $registry): JsonResponse
    {
        $this->authorizeAction('create automations');

        $template = $registry->get($handle);
        if ($template === null) {
            return response()->json(['message' => "Template '{$handle}' not found."], 404);
        }

        $automation = DB::transaction(function () use ($template) {
            $suffix = Str::lower(Str::random(4));

            $automation = Automation::create([
                'name' => $template['name'],
                'handle' => Str::slug($template['handle']).'-'.$suffix,
                'description' => $template['description'] ?? null,
                'enabled' => false,
                'created_by' => optional(auth()->user())->id,
            ]);

            foreach ($template['nodes'] ?? [] as $node) {
                AutomationNode::create([
                    'automation_id' => $automation->id,
                    'node_key' => $node['node_key'],
                    'type' => $node['type'],
                    'label' => $node['label'] ?? null,
                    'position_x' => (int) ($node['position_x'] ?? 0),
                    'position_y' => (int) ($node['position_y'] ?? 0),
                    'config' => $node['config'] ?? [],
                ]);
            }

            foreach ($template['edges'] ?? [] as $edge) {
                AutomationEdge::create([
                    'automation_id' => $automation->id,
                    'from_node_key' => $edge['from_node_key'],
                    'from_output' => $edge['from_output'] ?? 'default',
                    'to_node_key' => $edge['to_node_key'],
                    'to_input' => $edge['to_input'] ?? 'default',
                ]);
            }

            return $automation;
        });

        return (new AutomationResource($automation->fresh(['nodes', 'edges'])))
            ->response()
            ->setStatusCode(201);
    }
}
