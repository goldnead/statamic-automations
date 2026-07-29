<?php

namespace Goldnead\StatamicAutomations\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Real-shaped automations data, insertable into any released schema.
 *
 * A migration test is only worth running against rows, and rows are the one
 * thing this addon's migration coverage never had. The awkward part is that
 * the schema those rows go into changes underneath them — `automation_uuid`
 * appears in 1.0.x, `brand_id` in 1.5.0, millisecond timestamps in 1.5.6 — so
 * a fixture with a fixed column list can only seed one version of the
 * database.
 *
 * This one asks the schema what it has. Every row is built at its widest, then
 * reduced to the columns that exist at the moment of the insert, and anything
 * NOT NULL that the fixture does not know about is filled generically so that
 * a migration added next year is seeded without this file being touched. That
 * is the whole reason the migration test never names a migration file: a
 * fixture that has to be edited for every new column is a fixture that stops
 * covering new migrations the day it is written.
 *
 * The shape is the one a working install has: several automations with
 * distinct handles, a small graph of nodes and edges under each, runs and node
 * runs beneath those, a pending scheduled job, and audit rows. One run is
 * deliberately parentless — that is a flat-file run, which is legal here
 * (`automation_id` is nullable, null-on-delete) and is the row the brand
 * backfill has to stamp from the default brand rather than from a parent.
 */
class AutomationsDataFixture
{
    /**
     * The automations. Handles are distinct on purpose: the constraint under
     * test is that they stay that way.
     *
     * @var list<array{handle: string, name: string, enabled: int}>
     */
    public const AUTOMATIONS = [
        ['handle' => 'welcome-serie', 'name' => 'Welcome-Serie', 'enabled' => 1],
        ['handle' => 'lead-scoring', 'name' => 'Lead-Scoring', 'enabled' => 1],
        ['handle' => 'abo-erinnerung', 'name' => 'Abo-Erinnerung', 'enabled' => 0],
        ['handle' => 'chorleiter-onboarding', 'name' => 'Chorleiter-Onboarding', 'enabled' => 1],
    ];

    /**
     * The node graph every automation gets. `node_key` is unique per
     * automation, which is a constraint of its own and one the fixture must
     * not trip over while seeding several generations.
     *
     * @var list<array{key: string, type: string, label: string}>
     */
    public const NODES = [
        ['key' => 'trigger', 'type' => 'entry_published', 'label' => 'Eintrag veröffentlicht'],
        ['key' => 'wait', 'type' => 'delay', 'label' => 'Warten'],
        ['key' => 'notify', 'type' => 'send_email', 'label' => 'E-Mail senden'],
    ];

    /**
     * from => to, by node key.
     *
     * @var list<array{from: string, to: string}>
     */
    public const EDGES = [
        ['from' => 'trigger', 'to' => 'wait'],
        ['from' => 'wait', 'to' => 'notify'],
    ];

    /**
     * The handle used to prove the handle unique still bites. Real fixture
     * data rather than a row invented for the assertion.
     */
    public const HANDLE_PROBE = 'welcome-serie';

    /**
     * The handle to probe a given seed batch with.
     */
    public static function handleProbe(int $batch = 0): string
    {
        return self::HANDLE_PROBE.self::suffix($batch);
    }

    public function __construct(private Connection $connection) {}

    /**
     * Put one full generation of data into every automations table that
     * exists.
     *
     * Repeatable: pass a different `$batch` to add another generation without
     * colliding with the last one. Batch 0 is the fixture above verbatim, so
     * assertions can name a row by hand.
     *
     * @return int the number of automation rows written
     */
    public function seed(int $batch = 0): int
    {
        $suffix = self::suffix($batch);

        if (! $this->has('automations')) {
            return 0;
        }

        $automationIds = [];

        foreach (self::AUTOMATIONS as $index => $automation) {
            $automationIds[$index] = $this->insert('automations', [
                'uuid' => (string) Str::uuid(),
                'name' => $automation['name'],
                'handle' => $automation['handle'].$suffix,
                'description' => 'Fixture-Automation für den Migrationspfad.',
                'enabled' => $automation['enabled'],
                'version' => 1,
                'created_by' => 'fixture',
                'last_run_at' => now(),
            ]);
        }

        if ($this->has('automation_nodes')) {
            foreach ($automationIds as $automationId) {
                foreach (self::NODES as $node) {
                    $this->insert('automation_nodes', [
                        'uuid' => (string) Str::uuid(),
                        'automation_id' => $automationId,
                        'node_key' => $node['key'],
                        'type' => $node['type'],
                        'label' => $node['label'],
                        'position_x' => 0,
                        'position_y' => 0,
                        // A delay node without a `unit`, which is precisely the
                        // row 2026_07_28_000001 exists to repair.
                        'config' => json_encode($node['type'] === 'delay' ? ['amount' => 15] : []),
                        'disabled' => 0,
                    ]);
                }
            }
        }

        if ($this->has('automation_edges')) {
            foreach ($automationIds as $automationId) {
                foreach (self::EDGES as $edge) {
                    $this->insert('automation_edges', [
                        'uuid' => (string) Str::uuid(),
                        'automation_id' => $automationId,
                        'from_node_key' => $edge['from'],
                        'from_output' => 'default',
                        'to_node_key' => $edge['to'],
                        'to_input' => 'default',
                    ]);
                }
            }
        }

        $runIds = [];

        if ($this->has('automation_runs')) {
            foreach (array_slice($automationIds, 0, 2) as $automationId) {
                foreach (['completed', 'failed'] as $status) {
                    $runIds[] = $this->insert('automation_runs', [
                        'uuid' => (string) Str::uuid(),
                        'automation_id' => $automationId,
                        'automation_uuid' => (string) Str::uuid(),
                        'trigger_node_key' => 'trigger',
                        'trigger_type' => 'entry_published',
                        'status' => $status,
                        'context' => json_encode(['source' => 'fixture']),
                        'started_at' => now()->subMinute(),
                        'finished_at' => now(),
                        'duration_ms' => 1234,
                        'error_message' => $status === 'failed' ? 'Fixture-Fehler' : null,
                        'is_test' => 0,
                    ]);
                }
            }

            // A flat-file run: no parent row to inherit a brand from. The
            // backfill has to reach it from the default brand instead.
            $runIds[] = $this->insert('automation_runs', [
                'uuid' => (string) Str::uuid(),
                'automation_id' => null,
                'automation_uuid' => (string) Str::uuid(),
                'trigger_node_key' => 'trigger',
                'trigger_type' => 'manual',
                'status' => 'completed',
                'context' => json_encode(['source' => 'flatfile']),
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
                'duration_ms' => 42,
                'error_message' => null,
                'is_test' => 0,
            ]);
        }

        if ($this->has('automation_node_runs')) {
            foreach ($runIds as $runId) {
                foreach (self::NODES as $node) {
                    $this->insert('automation_node_runs', [
                        'uuid' => (string) Str::uuid(),
                        'automation_run_id' => $runId,
                        'node_key' => $node['key'],
                        'node_type' => $node['type'],
                        'status' => 'completed',
                        'input' => json_encode(['in' => true]),
                        'output' => json_encode(['out' => true]),
                        'error_message' => null,
                        'started_at' => now()->subMinute(),
                        'finished_at' => now(),
                        'duration_ms' => 7,
                    ]);
                }
            }
        }

        if ($this->has('automation_scheduled_jobs') && $automationIds !== []) {
            $this->insert('automation_scheduled_jobs', [
                'uuid' => (string) Str::uuid(),
                'automation_id' => $automationIds[0],
                'automation_run_id' => $runIds[0] ?? null,
                'node_key' => 'wait',
                'due_at' => now()->addHour(),
                'status' => 'pending',
                'payload' => json_encode(['resume' => 'notify']),
            ]);
        }

        if ($this->has('automation_audit_logs')) {
            foreach ($automationIds as $automationId) {
                $this->insert('automation_audit_logs', [
                    'automation_id' => $automationId,
                    'action' => 'created',
                    'user_id' => 'fixture-user',
                    'user_label' => 'Fixture',
                    'meta' => json_encode(['batch' => $batch]),
                ]);
            }

            // A global audit entry with no automation behind it — the other
            // row the brand backfill can only stamp from the default brand.
            $this->insert('automation_audit_logs', [
                'automation_id' => null,
                'action' => 'imported',
                'user_id' => null,
                'user_label' => null,
                'meta' => json_encode(['batch' => $batch]),
            ]);
        }

        return count($automationIds);
    }

    /**
     * How many rows every automations table currently holds.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::tables() as $table) {
            if ($this->has($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            'automations',
            'automation_nodes',
            'automation_edges',
            'automation_runs',
            'automation_node_runs',
            'automation_scheduled_jobs',
            'automation_audit_logs',
        ];
    }

    private static function suffix(int $batch): string
    {
        return $batch === 0 ? '' : '-b'.$batch;
    }

    private function has(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }

    /**
     * Reduce a row to the columns the table has today, add timestamps, fill
     * any NOT NULL column the fixture does not know about, and insert.
     *
     * @param  array<string, mixed>  $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = collect($this->connection->getSchemaBuilder()->getColumns($table))
            ->keyBy('name');

        $row = collect($row)
            ->only($columns->keys()->all())
            ->all();

        // `automation_audit_logs` has a `created_at` and no `updated_at`, so
        // the two are asked about separately rather than as a pair.
        foreach (['created_at', 'updated_at'] as $timestamp) {
            if ($columns->has($timestamp)) {
                $row[$timestamp] = now();
            }
        }

        if ($columns->has('brand_id') && ! isset($row['brand_id'])) {
            $row['brand_id'] = $this->defaultBrandId();
        }

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            if (($column['auto_increment'] ?? false) || ($column['nullable'] ?? true) || ($column['default'] ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->genericValueFor($column, $table, $name);
        }

        return (int) $this->connection->table($table)->insertGetId($row);
    }

    /**
     * A value for a NOT NULL column this fixture has never heard of.
     *
     * Unique per row, because a column added by a future migration is most
     * likely to be added together with a unique over it — which is the shape
     * this whole file exists to catch.
     *
     * @param  array<string, mixed>  $column
     */
    private function genericValueFor(array $column, string $table, string $name): string|int
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));

        return match (true) {
            str_contains($type, 'int') => random_int(1, PHP_INT_MAX),
            str_contains($type, 'bool') => 0,
            str_contains($type, 'date'), str_contains($type, 'time') => (string) now(),
            default => substr(hash('sha256', $table.$name.Str::uuid()), 0, 32),
        };
    }

    private function defaultBrandId(): ?int
    {
        if (! $this->has('brands')) {
            return null;
        }

        return $this->connection->table('brands')->where('is_default', true)->value('id')
            ?? $this->connection->table('brands')->min('id');
    }
}
