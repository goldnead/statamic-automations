<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Measures every index these migrations create the way MySQL would.
 *
 * The suite runs on in-memory SQLite by default, and SQLite has no index key
 * limit, no per-character byte cost and no fixed column widths — it accepts
 * `varchar(255)` and ignores the 255. So a green suite says nothing about
 * whether InnoDB would accept the same schema. `statamic-notifications` v1.0.3
 * shipped a unique of 3212 bytes on exactly that basis: the migration had run
 * hundreds of times locally and failed on the production hub with
 * *SQLSTATE 1071: Specified key was too long; max key length is 3072 bytes*,
 * leaving two tables that never existed there at all.
 *
 * This test does not ask a database. It compiles this addon's own migration
 * files through Laravel's MySQL grammar in pretend mode — no server, no
 * connection, nothing to install in CI — and measures the DDL MySQL would have
 * received. It reads the real migration files, so it cannot drift from them.
 *
 * Ported from `statamic-notifications` v1.0.4 by way of
 * `statamic-webhook-manager` v1.6.1. This addon needs the extended version:
 * its schema is built across eleven migrations, and `brand_id` — the whole
 * tenant boundary — arrives by `alter table … add` long after the create
 * migrations, together with the drop of the global handle unique and the
 * per-brand one that replaces it. All four statement shapes are replayed, so
 * what is measured is the schema at the end of the run rather than the one the
 * create migrations described.
 */
class IndexKeyLengthTest extends TestCase
{
    /** InnoDB refuses any index wider than this, in bytes. */
    private const MAX_KEY_BYTES = 3072;

    public function test_every_index_fits_inside_the_innodb_key_limit(): void
    {
        $schema = $this->compileMigrationsForMysql();

        $this->assertNotEmpty($schema['indexes']);

        foreach ($schema['indexes'] as $index) {
            $bytes = $this->widthOf($index, $schema);

            $this->assertLessThanOrEqual(
                self::MAX_KEY_BYTES,
                $bytes,
                "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
                'InnoDB allows '.self::MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
            );
        }
    }

    public function test_every_index_leaves_room_for_another_column(): void
    {
        // Being under the limit by accident is what made the notifications
        // schema fragile: its digest-runs unique sat at 2196 of 3072 and would
        // have broken on the next column added to it. Headroom is asserted
        // rather than hoped for — no index here may spend half the limit.
        $schema = $this->compileMigrationsForMysql();

        foreach ($schema['indexes'] as $index) {
            $bytes = $this->widthOf($index, $schema);

            $this->assertLessThan(
                self::MAX_KEY_BYTES / 2,
                $bytes,
                "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
                'so the next column added to it is likely to break the migration.'
            );
        }
    }

    public function test_no_unique_index_covers_a_column_that_may_be_null(): void
    {
        // A SQL unique does not constrain NULL, on any engine: two rows that
        // are identical except for a NULL in one of the indexed columns are
        // both accepted, without limit. Where a column of a unique is nullable
        // the constraint therefore silently stops applying to every row that
        // leaves it empty — which is how notifications ended up enforcing
        // nothing at all for contact recipients, in the very table written to
        // keep them unique. A unique whose name promises uniqueness must cover
        // NOT NULL columns only.
        $schema = $this->compileMigrationsForMysql();

        $uniques = array_filter($schema['indexes'], fn ($index) => $index['unique']);

        $this->assertNotEmpty($uniques);

        foreach ($uniques as $index) {
            foreach ($index['columns'] as $column) {
                $this->assertFalse(
                    $schema['columns'][$index['table']][$column]['nullable'] ?? true,
                    "Unique {$index['name']} on {$index['table']} covers the nullable column ".
                    "{$column}. Rows leaving it NULL are not constrained at all, so the index ".
                    'does not enforce what its name claims.'
                );
            }
        }
    }

    public function test_the_automation_handle_is_unique_per_brand_rather_than_globally(): void
    {
        // Tenant separation has to stay legible in the schema: a handle belongs
        // to one brand, so brand_id is the first column of the unique and not
        // an ingredient hashed into something else. The uuid uniques stay
        // global on purpose — a UUID is unique by construction and carries no
        // tenant meaning.
        $schema = $this->compileMigrationsForMysql();

        $uniques = [];

        foreach ($schema['indexes'] as $index) {
            if ($index['unique']) {
                $uniques[$index['name']] = $index['columns'];
            }
        }

        $this->assertArrayNotHasKey(
            'automations_handle_unique',
            $uniques,
            'automations still carries a globally unique handle; one brand would block the handle for all others.'
        );

        $this->assertSame(
            ['brand_id', 'handle'],
            $uniques['automations_brand_id_handle_unique'] ?? null,
            'The automation handle must be unique per brand, with brand_id leading the index.'
        );

        // The node key is unique per automation, and an automation belongs to
        // exactly one brand, so the tenant boundary is inherited rather than
        // repeated. Asserted so a later widening of this index is a decision.
        $this->assertSame(
            ['automation_id', 'node_key'],
            $uniques['automation_nodes_automation_id_node_key_unique'] ?? null,
            'The node key must stay unique per automation.'
        );
    }

    /** Worst-case index bytes this index occupies under utf8mb4. */
    private function widthOf(array $index, array $schema): int
    {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $definition = $schema['columns'][$index['table']][$column] ?? null;

            $this->assertNotNull(
                $definition,
                "Index {$index['name']} covers unknown column {$column}."
            );

            $bytes += $definition['bytes'];
        }

        return $bytes;
    }

    /**
     * Replays every migration against a MySQL connection that is never opened
     * and returns the column widths and index definitions MySQL would end up
     * with.
     *
     * @return array{columns: array<string, array<string, array{bytes: int, nullable: bool}>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
     */
    private function compileMigrationsForMysql(): array
    {
        config()->set('database.connections.key_length_probe', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'key_length_probe',
            'username' => 'probe',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);

        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('key_length_probe');

        try {
            // pretend() short-circuits every statement before a PDO instance is
            // needed, so this compiles the DDL without a server in sight.
            $queries = DB::connection('key_length_probe')->pretend(function () {
                foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                    (require $file)->up();
                }
            });
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('key_length_probe');
        }

        $columns = [];
        $indexes = [];

        foreach (array_column($queries, 'query') as $sql) {
            if (preg_match('/^create table `(\w+)` \((.*?)\)(?: default character set| collate|$)/s', $sql, $match)) {
                foreach ($this->splitTopLevel($match[2]) as $definition) {
                    if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                        $columns[$match[1]][$column[1]] = $this->describeColumn($column[2]);
                    }
                }

                continue;
            }

            if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
                $indexes[$match[3]] = [
                    'table' => $match[1],
                    'name' => $match[3],
                    'unique' => $match[2] === 'unique',
                    'columns' => array_map(
                        fn ($column) => trim($column, ' `'),
                        explode(',', $match[4])
                    ),
                ];

                continue;
            }

            // Columns added or retyped by a later migration. Without these the
            // schema measured would be the one the create migrations described,
            // not the one that ends up on disk — and brand_id, this addon's
            // whole tenant boundary, arrives exactly this way.
            if (preg_match('/^alter table `(\w+)` (add|modify) (`\w+` .+)$/', $sql, $match)) {
                foreach (explode(', '.$match[2].' ', $match[3]) as $definition) {
                    if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                        $columns[$match[1]][$column[1]] = $this->describeColumn($column[2]);
                    }
                }

                continue;
            }

            if (preg_match('/^alter table `(\w+)` drop index `(\w+)`$/', $sql, $match)) {
                unset($indexes[$match[2]]);
            }
        }

        return ['columns' => $columns, 'indexes' => array_values($indexes)];
    }

    /** Splits a column list on commas that are not inside parentheses. */
    private function splitTopLevel(string $list): array
    {
        $parts = [];
        $depth = 0;
        $buffer = '';

        foreach (str_split($list) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        return array_merge($parts, [$buffer]);
    }

    /**
     * Worst-case index bytes and nullability for one compiled column definition.
     *
     * @return array{bytes: int, nullable: bool}
     */
    private function describeColumn(string $type): array
    {
        return [
            'bytes' => $this->indexBytes($type),
            // The grammar always states one or the other explicitly, so the
            // absence of "not null" is a decision rather than a default.
            'nullable' => ! str_contains($type, ' not null'),
        ];
    }

    private function indexBytes(string $type): int
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
            return (int) $match[1] * 4; // utf8mb4: four bytes per character.
        }

        return match (true) {
            str_starts_with($type, 'tinyint') => 1,
            str_starts_with($type, 'smallint') => 2,
            str_starts_with($type, 'mediumint') => 3,
            str_starts_with($type, 'int') => 4,
            str_starts_with($type, 'bigint') => 8,
            str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
            str_starts_with($type, 'date') => 3,
            // Blobs, text and json cannot be indexed whole at all. Reported as
            // oversized so an index that reaches for one fails here rather than
            // on MySQL.
            default => self::MAX_KEY_BYTES + 1,
        };
    }
}
