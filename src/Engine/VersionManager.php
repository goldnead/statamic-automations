<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Contracts\Revisions\Revision;
use Statamic\Facades\Revision as Revisions;

/**
 * Versions an automation's graph using Statamic's native Revisions system.
 *
 * Each save writes a flat-file YAML revision (under Statamic's revisions
 * store) whose attributes are a self-contained snapshot of the automation
 * meta plus every node and edge. This reuses Statamic's own history format
 * and storage rather than a bespoke database table, so automation history
 * lives alongside content revisions and is portable with the rest of the
 * site's flat files.
 *
 * Reverting writes a snapshot back over the live graph (and snapshots the
 * current state first, so a revert is itself reversible).
 */
class VersionManager
{
    /**
     * Capture the current graph as a new revision. Returns the revision,
     * or null when versioning is disabled.
     */
    public function snapshot(Automation $automation, ?string $message = null): ?Revision
    {
        if (! config('automations.versioning.enabled', true)) {
            return null;
        }

        $automation->loadMissing(['nodes', 'edges']);

        $revision = Revisions::make()
            ->key($this->key($automation))
            ->action('revision')
            ->date(Carbon::now())
            ->message($message)
            ->attributes($this->buildSnapshot($automation));

        if ($userId = optional(auth()->user())->id) {
            $revision->user($userId);
        }

        $revision->save();

        $this->prune($automation);

        return $revision;
    }

    /**
     * List the stored revisions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versions(Automation $automation): array
    {
        return Revisions::whereKey($this->key($automation))
            ->sortByDesc(fn (Revision $r) => $r->date()->timestamp)
            ->map(fn (Revision $r) => [
                'timestamp' => $r->date()->timestamp,
                'date' => $r->date()->toIso8601String(),
                'message' => $r->message(),
                'user' => optional($r->user())->email(),
                'node_count' => count($r->attributes()['nodes'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Restore a revision (identified by its unix timestamp) onto the live
     * automation. The current state is snapshotted first.
     */
    public function revert(Automation $automation, int $timestamp): Automation
    {
        $revision = Revisions::whereKey($this->key($automation))->get($timestamp);

        if ($revision === null) {
            throw new \RuntimeException("Revision '{$timestamp}' not found.");
        }

        return DB::transaction(function () use ($automation, $revision) {
            $this->snapshot($automation, 'Auto-saved before revert');

            $snapshot = $revision->attributes();

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
     * The revision key namespaces automation history away from content
     * revisions (entries/terms) in the same store.
     */
    public function key(Automation $automation): string
    {
        return 'automation::' . ($automation->uuid ?: $automation->id);
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

        Revisions::whereKey($this->key($automation))
            ->sortByDesc(fn (Revision $r) => $r->date()->timestamp)
            ->slice($keep)
            ->each(fn (Revision $r) => Revisions::delete($r));
    }
}
