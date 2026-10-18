<?php

namespace Goldnead\StatamicAutomations\Repositories;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Thumbnails\MailThumbnails;
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
        if (! Automation::schemaReady()) {
            return collect();
        }

        return Automation::query()->with(['nodes', 'edges'])->get();
    }

    public function enabled(): Collection
    {
        if (! Automation::schemaReady()) {
            return collect();
        }

        return Automation::query()->where('enabled', true)->with(['nodes', 'edges'])->get();
    }

    public function find(int|string $id): ?Automation
    {
        return Automation::query()
            ->where(fn ($query) => $this->matchingIdOrUuid($query, $id))
            ->with(['nodes', 'edges'])
            ->first();
    }

    /**
     * Compare the numeric key only with a number.
     *
     * `where id = '3f2a…'` is not false on MySQL, it is `id = 3`: the engine reads the
     * leading digits of a UUID as the number and returns the automation with that id,
     * ahead of the one the UUID names. SQLite compares the strings and never shows it, so
     * a run could resume on the wrong graph on production and nowhere else.
     */
    protected function matchingIdOrUuid($query, int|string $value): void
    {
        if (is_int($value) || ctype_digit($value)) {
            $query->where('id', $value)->orWhere('uuid', (string) $value);

            return;
        }

        $query->where('uuid', $value);
    }

    public function findByRef(string $ref): ?Automation
    {
        return Automation::query()
            ->where(function ($query) use ($ref) {
                $query->where('handle', $ref);

                if (ctype_digit($ref)) {
                    $query->orWhere('id', $ref);
                }

                $query->orWhere('uuid', $ref);
            })
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
        MailThumbnails::forget($automation);

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
