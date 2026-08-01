<?php

namespace Goldnead\StatamicAutomations\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AutomationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description,
            'enabled' => (bool) $this->enabled,
            'version' => $this->version,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'runs_count' => $this->runs_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Handed out so callers never have to build it themselves. The
            // templates screen used to derive its post-install redirect with
            // `location.pathname.replace('/templates', '/automations/'+id+'/edit')`,
            // which only worked because the create route happens to carry a
            // doubled `automations/automations` segment.
            'edit_url' => cp_route('statamic-automations.automations.edit', $this->id),

            'nodes' => $this->whenLoaded('nodes', function () {
                return $this->nodes->map(fn ($node) => [
                    'node_key' => $node->node_key,
                    'type' => $node->type,
                    'label' => $node->label,
                    'position_x' => $node->position_x,
                    'position_y' => $node->position_y,
                    'config' => $node->config ?? [],
                    'disabled' => (bool) $node->disabled,
                ])->values()->all();
            }),

            'edges' => $this->whenLoaded('edges', function () {
                return $this->edges->map(fn ($edge) => [
                    'from_node_key' => $edge->from_node_key,
                    'from_output' => $edge->from_output,
                    'to_node_key' => $edge->to_node_key,
                    'to_input' => $edge->to_input,
                ])->values()->all();
            }),
        ];
    }
}
