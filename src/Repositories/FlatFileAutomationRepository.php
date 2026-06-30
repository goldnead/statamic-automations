<?php

namespace Goldnead\StatamicAutomations\Repositories;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\YAML;

/**
 * Flat-file driver: automation definitions live as YAML files, one per
 * automation, under a configured directory. The authoritative graph (nodes
 * + edges) is the file; the database is never touched for definitions.
 *
 * Loaded automations are hydrated as **non-persisted** Eloquent model
 * instances with their relations set, so the engine consumes them exactly
 * like database-backed ones. A deterministic integer id is derived from the
 * uuid so that runtime tables keyed by `automation_id` stay stable across
 * loads; the uuid remains the canonical reference.
 */
class FlatFileAutomationRepository implements AutomationRepository
{
    public function all(): Collection
    {
        if (! File::isDirectory($this->directory())) {
            return collect();
        }

        return collect(File::files($this->directory()))
            ->filter(fn ($file) => $file->getExtension() === 'yaml')
            ->map(function ($file) {
                $automation = $this->hydrate(YAML::parse(File::get($file->getPathname())));

                // Surface the file's modification time as updated_at so the
                // CP listing shows a real date instead of the epoch.
                if ($automation && ($mtime = $file->getMTime())) {
                    $automation->updated_at = \Illuminate\Support\Carbon::createFromTimestamp($mtime);
                }

                return $automation;
            })
            ->filter()
            ->values();
    }

    public function enabled(): Collection
    {
        return $this->all()->filter(fn (Automation $a) => (bool) $a->enabled)->values();
    }

    public function find(int|string $id): ?Automation
    {
        return $this->all()->first(
            fn (Automation $a) => (string) $a->id === (string) $id || $a->uuid === $id
        );
    }

    public function findByRef(string $ref): ?Automation
    {
        return $this->all()->first(fn (Automation $a) => $a->handle === $ref
            || (string) $a->id === $ref
            || $a->uuid === $ref);
    }

    public function save(Automation $automation, ?array $nodes = null, ?array $edges = null): Automation
    {
        if (empty($automation->uuid)) {
            $automation->uuid = (string) Str::uuid();
        }

        // When the caller didn't pass a graph, preserve whatever is already
        // loaded on the model (e.g. a meta-only save like last_run_at).
        $nodes ??= $automation->relationLoaded('nodes')
            ? $automation->nodes->map(fn ($n) => $n->only([
                'node_key', 'type', 'label', 'position_x', 'position_y', 'config', 'disabled',
            ]))->all()
            : [];
        $edges ??= $automation->relationLoaded('edges')
            ? $automation->edges->map(fn ($e) => $e->only([
                'from_node_key', 'from_output', 'to_node_key', 'to_input',
            ]))->all()
            : [];

        $payload = [
            'uuid' => $automation->uuid,
            'name' => $automation->name,
            'handle' => $automation->handle,
            'description' => $automation->description,
            'enabled' => (bool) $automation->enabled,
            'version' => (int) ($automation->version ?: 1),
            'created_by' => $automation->created_by,
            'last_run_at' => optional($automation->last_run_at)->toIso8601String(),
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
        ];

        File::ensureDirectoryExists($this->directory());
        File::put($this->pathFor($automation->handle), YAML::dump($payload));

        return $this->hydrate($payload);
    }

    public function delete(Automation $automation): void
    {
        $path = $this->pathFor($automation->handle);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    public function count(): int
    {
        return $this->all()->count();
    }

    public function enabledCount(): int
    {
        return $this->enabled()->count();
    }

    /**
     * Build a non-persisted Automation (with relations) from a parsed file.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function hydrate(?array $data): ?Automation
    {
        if (! is_array($data) || empty($data['handle'])) {
            return null;
        }

        $uuid = $data['uuid'] ?? (string) Str::uuid();

        $automation = new Automation([
            'uuid' => $uuid,
            'name' => $data['name'] ?? $data['handle'],
            'handle' => $data['handle'],
            'description' => $data['description'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? false),
            'version' => (int) ($data['version'] ?? 1),
            'created_by' => $data['created_by'] ?? null,
            'last_run_at' => $data['last_run_at'] ?? null,
        ]);
        $automation->id = $this->deterministicId($uuid);
        $automation->exists = false;

        $automation->setRelation('nodes', collect($data['nodes'] ?? [])->map(function ($node) use ($automation) {
            $model = new AutomationNode([
                'node_key' => $node['node_key'],
                'type' => $node['type'],
                'label' => $node['label'] ?? null,
                'position_x' => (int) ($node['position_x'] ?? 0),
                'position_y' => (int) ($node['position_y'] ?? 0),
                'config' => $node['config'] ?? [],
                'disabled' => (bool) ($node['disabled'] ?? false),
            ]);
            $model->automation_id = $automation->id;
            $model->exists = false;

            return $model;
        }));

        $automation->setRelation('edges', collect($data['edges'] ?? [])->map(function ($edge) use ($automation) {
            $model = new AutomationEdge([
                'from_node_key' => $edge['from_node_key'],
                'from_output' => $edge['from_output'] ?? 'default',
                'to_node_key' => $edge['to_node_key'],
                'to_input' => $edge['to_input'] ?? 'default',
            ]);
            $model->automation_id = $automation->id;
            $model->exists = false;

            return $model;
        }));

        return $automation;
    }

    /**
     * Stable positive 31-bit integer id derived from the uuid, so runtime
     * rows keyed by automation_id remain consistent across reloads.
     */
    protected function deterministicId(string $uuid): int
    {
        return (int) (crc32($uuid) & 0x7fffffff);
    }

    protected function directory(): string
    {
        $path = config('automations.storage.flat_file.path')
            ?: config('automations.file_storage.path')
            ?: base_path('resources/automations');

        return rtrim($path, '/');
    }

    protected function pathFor(string $handle): string
    {
        return $this->directory() . '/' . $handle . '.yaml';
    }
}
