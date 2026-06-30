<?php

namespace Goldnead\StatamicAutomations\Repositories;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Default driver: automation definitions are Eloquent rows. This preserves
 * the addon's original database-backed behaviour exactly.
 */
class DatabaseAutomationRepository implements AutomationRepository
{
    public function all(): Collection
    {
        return Automation::query()->with(['nodes', 'edges'])->get();
    }

    public function enabled(): Collection
    {
        return Automation::query()->where('enabled', true)->with(['nodes', 'edges'])->get();
    }

    public function find(int|string $id): ?Automation
    {
        return Automation::query()
            ->where('id', $id)
            ->orWhere('uuid', $id)
            ->with(['nodes', 'edges'])
            ->first();
    }

    public function findByRef(string $ref): ?Automation
    {
        return Automation::query()
            ->where('handle', $ref)
            ->orWhere('id', $ref)
            ->orWhere('uuid', $ref)
            ->with(['nodes', 'edges'])
            ->first();
    }

    public function save(Automation $automation, ?array $nodes = null, ?array $edges = null): Automation
    {
        return DB::transaction(function () use ($automation, $nodes, $edges) {
            $automation->save();

            if ($nodes !== null || $edges !== null) {
                $automation->edges()->delete();
                $automation->nodes()->delete();

                foreach ($nodes ?? [] as $node) {
                    AutomationNode::create([
                        'automation_id' => $automation->id,
                        'node_key' => $node['node_key'],
                        'type' => $node['type'],
                        'label' => $node['label'] ?? null,
                        'position_x' => (int) ($node['position_x'] ?? 0),
                        'position_y' => (int) ($node['position_y'] ?? 0),
                        'config' => $node['config'] ?? [],
                        'disabled' => (bool) ($node['disabled'] ?? false),
                    ]);
                }

                foreach ($edges ?? [] as $edge) {
                    AutomationEdge::create([
                        'automation_id' => $automation->id,
                        'from_node_key' => $edge['from_node_key'],
                        'from_output' => $edge['from_output'] ?? 'default',
                        'to_node_key' => $edge['to_node_key'],
                        'to_input' => $edge['to_input'] ?? 'default',
                    ]);
                }
            }

            return $automation->fresh(['nodes', 'edges']);
        });
    }

    public function delete(Automation $automation): void
    {
        $automation->delete();
    }

    public function count(): int
    {
        return Automation::query()->count();
    }

    public function enabledCount(): int
    {
        return Automation::query()->where('enabled', true)->count();
    }
}
