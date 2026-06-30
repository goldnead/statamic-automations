<?php

namespace Goldnead\StatamicAutomations\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AutomationRunResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'automation_id' => $this->automation_id,
            'automation_uuid' => $this->automation_uuid,
            'automation' => $this->whenLoaded('automation', fn () => [
                'id' => $this->automation->id,
                'name' => $this->automation->name,
                'handle' => $this->automation->handle,
            ]),
            'trigger_node_key' => $this->trigger_node_key,
            'trigger_type' => $this->trigger_type,
            'status' => $this->status,
            'context' => $this->context,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'error_message' => $this->error_message,
            'is_test' => (bool) $this->is_test,
            'created_at' => $this->created_at?->toIso8601String(),
            'node_runs' => $this->whenLoaded('nodeRuns', function () {
                return $this->nodeRuns->sortBy('id')->values()->map(fn ($r) => [
                    'id' => $r->id,
                    'node_key' => $r->node_key,
                    'node_type' => $r->node_type,
                    'status' => $r->status,
                    'input' => $r->input,
                    'output' => $r->output,
                    'error_message' => $r->error_message,
                    'started_at' => $r->started_at?->toIso8601String(),
                    'finished_at' => $r->finished_at?->toIso8601String(),
                    'duration_ms' => $r->duration_ms,
                ])->all();
            }),
        ];
    }
}
