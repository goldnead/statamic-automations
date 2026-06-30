<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationVersion;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots an automation's graph so changes are reversible.
 *
 * A snapshot is a self-contained representation of the automation meta plus
 * every node and edge. Reverting writes the snapshot back over the live
 * graph (and itself snapshots first, so a revert is also undoable).
 */
class VersionManager
{
    /**
     * Capture the current graph as a new version. Returns the version row,
     * or null if snapshotting is disabled.
     */
    public function snapshot(Automation $automation, ?string $label = null): ?AutomationVersion
    {
        if (! config('automations.versioning.enabled', true)) {
            return null;
        }

        $automation->loadMissing(['nodes', 'edges']);

        // Snapshots use their own monotonic sequence per automation, so the
        // history stays append-only even when several snapshots are taken at
        // the same live automation version (e.g. an auto-save before revert).
        $next = (int) AutomationVersion::where('automation_id', $automation->id)->max('version') + 1;

        $version = AutomationVersion::create([
            'automation_id' => $automation->id,
            'version' => $next,
            'label' => $label,
            'snapshot' => $this->buildSnapshot($automation),
            'created_by' => optional(auth()->user())->id,
        ]);

        $this->prune($automation);

        return $version;
    }

    /**
     * Restore a previously captured version onto the live automation. The
     * current state is snapshotted first so the revert can be undone.
     */
    public function revert(Automation $automation, AutomationVersion $version): Automation
    {
        return DB::transaction(function () use ($automation, $version) {
            // Snapshot current state before overwriting.
            $this->snapshot($automation, 'Auto-saved before revert to v' . $version->version);

            $snapshot = $version->snapshot;

            $automation->fill([
                'name' => $snapshot['name'] ?? $automation->name,
                'description' => $snapshot['description'] ?? $automation->description,
            ]);
            $automation->version = (int) $automation->version + 1;
            $automation->save();

            $automation->edges()->delete();
            $automation->nodes()->delete();

            foreach ($snapshot['nodes'] ?? [] as $node) {
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

            foreach ($snapshot['edges'] ?? [] as $edge) {
                AutomationEdge::create([
                    'automation_id' => $automation->id,
                    'from_node_key' => $edge['from_node_key'],
                    'from_output' => $edge['from_output'] ?? 'default',
                    'to_node_key' => $edge['to_node_key'],
                    'to_input' => $edge['to_input'] ?? 'default',
                ]);
            }

            return $automation->fresh(['nodes', 'edges']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSnapshot(Automation $automation): array
    {
        return [
            'name' => $automation->name,
            'handle' => $automation->handle,
            'description' => $automation->description,
            'nodes' => $automation->nodes->map(fn (AutomationNode $n) => [
                'node_key' => $n->node_key,
                'type' => $n->type,
                'label' => $n->label,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'config' => $n->config,
                'disabled' => $n->disabled,
            ])->values()->all(),
            'edges' => $automation->edges->map(fn (AutomationEdge $e) => [
                'from_node_key' => $e->from_node_key,
                'from_output' => $e->from_output,
                'to_node_key' => $e->to_node_key,
                'to_input' => $e->to_input,
            ])->values()->all(),
        ];
    }

    protected function prune(Automation $automation): void
    {
        $keep = (int) config('automations.versioning.keep', 25);
        if ($keep <= 0) {
            return;
        }

        $ids = AutomationVersion::where('automation_id', $automation->id)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            AutomationVersion::whereIn('id', $ids)->delete();
        }
    }
}
