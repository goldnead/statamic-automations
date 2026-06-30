<?php

namespace Goldnead\StatamicAutomations\Contracts;

use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Support\Collection;

/**
 * Abstraction over where automation **definitions** live.
 *
 * Two drivers ship with the addon:
 *   - database  — definitions are Eloquent rows (default).
 *   - flat_file — definitions are YAML files under a configured directory.
 *
 * Both drivers return {@see Automation} model instances with their `nodes`
 * and `edges` relations populated, so the engine consumes them identically
 * regardless of where they were loaded from. Runtime data (runs, node runs,
 * scheduled jobs, audit log) always lives in the database.
 */
interface AutomationRepository
{
    /**
     * @return Collection<int, Automation>
     */
    public function all(): Collection;

    /**
     * Enabled automations only — used for trigger discovery.
     *
     * @return Collection<int, Automation>
     */
    public function enabled(): Collection;

    /**
     * Find by primary id or uuid.
     */
    public function find(int|string $id): ?Automation;

    /**
     * Find by handle, then id/uuid — used by sub-automation lookups.
     */
    public function findByRef(string $ref): ?Automation;

    /**
     * Persist an automation's meta and, when provided, replace its graph.
     *
     * @param  array<int, array<string, mixed>>|null  $nodes
     * @param  array<int, array<string, mixed>>|null  $edges
     */
    public function save(Automation $automation, ?array $nodes = null, ?array $edges = null): Automation;

    public function delete(Automation $automation): void;

    public function count(): int;

    public function enabledCount(): int;
}
