<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 * This test asks no MySQL server. It compiles this addon's own migration files
 * through Laravel's MySQL grammar in pretend mode — nothing to install in CI —
 * and measures the DDL MySQL would have received. It reads the real migration
 * files, so it cannot drift from them.
 *
 * It does keep a throwaway in-memory SQLite database alongside, because the
 * migrations branch on the schema they find: `2026_07_24_100002` has to be
 * re-runnable, so it asks which tables already carry `brand_id` and which
 * indexes `automations` already has. Under `pretend()` every such question is
 * answered "nothing is there", which is a state no install is ever in. See
 * compileMigrationsForMysql() for how the two are kept in step.
 *
 * Ported from `statamic-notifications` v1.0.4 by way of
 * `statamic-webhook-manager` v1.6.1, and extended in the same shape
 * `statamic-marketing` 1.6.4 arrived at. This addon needs the extended
 * version: its schema is built across a dozen migrations, and `brand_id` — the
 * whole tenant boundary — arrives by `alter table … add` long after the create
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
     * Two connections, because a migration that branches on the schema needs
     * both halves and no single connection can give them:
     *
     * - the **probe** compiles the DDL. Its grammar is MySQL's, `pretend()`
     *   stops every statement before it reaches a driver, and the rendered SQL
     *   is what gets measured. It has no server and no schema of its own —
     *   under `pretend()` a `select` returns an empty array, so anything asked
     *   of it about the current schema comes back as "nothing is there".
     * - the **state** is a real SQLite database that the same migrations are
     *   run against for real, one file behind. It is what
     *   `Schema::hasColumn()`, `Schema::getIndexes()` and
     *   `Schema::getColumns()` are answered from.
     *
     * That split is not incidental. `2026_07_24_100002` asks which tables
     * already carry `brand_id` and which indexes `automations` already has,
     * because it has to be re-runnable on an install its own first run left
     * halfway through. A probe that answers "nothing is there" to both would
     * measure a schema no install ever has: it would compile the new unique
     * without the drop of the old one that precedes it, and then report that
     * the global handle unique is still in place.
     *
     * The two run interleaved, probe first: the DDL for migration N is
     * compiled against the schema as it stood after N-1, which is exactly what
     * the server sees.
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

        config()->set('database.connections.key_length_state', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $previous = DB::getDefaultConnection();

        DB::purge('key_length_probe');
        DB::purge('key_length_state');

        $probe = DB::connection('key_length_probe');
        $state = DB::connection('key_length_state');

        // pretend() renders every logged statement with its bindings
        // substituted, and substituting a binding goes through PDO::quote. So
        // without a PDO the probe would try to reach a MySQL server after all
        // — for the query log, not for the query. A throwaway SQLite handle
        // quotes values and is never asked to run anything: pretending()
        // short-circuits every statement before it reaches the driver, and the
        // grammar stays MySQL's, which is the thing being measured.
        $probe->setPdo(new \PDO('sqlite::memory:'));

        // The brand every existing row is backfilled onto. brand-context
        // creates this table; the state database only needs enough of it to
        // answer the lookup.
        $state->getSchemaBuilder()->create('brands', function ($table) {
            $table->id();
            $table->string('handle');
            $table->boolean('is_default')->default(false);
        });

        $state->table('brands')->insert(['handle' => 'default', 'is_default' => true]);

        // A connection resolves its schema grammar lazily, inside
        // getSchemaBuilder(). The oracle is constructed directly, so it has to
        // be asked for explicitly or every Blueprint the probe compiles gets a
        // null grammar.
        $probe->useDefaultSchemaGrammar();

        $oracle = new ProbeSchemaBuilder($probe, $state);

        $queries = [];

        try {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                $migration = require $file;

                // 1. What MySQL would be sent, decided on the schema as it stands.
                DB::setDefaultConnection('key_length_probe');
                app()->instance('db.schema', $oracle);
                Schema::clearResolvedInstance('db.schema');

                $queries = array_merge($queries, $probe->pretend(fn () => $migration->up()));

                // 2. Advance the real schema, so the next file branches on truth.
                DB::setDefaultConnection('key_length_state');
                app()->forgetInstance('db.schema');
                Schema::clearResolvedInstance('db.schema');

                $migration->up();
            }
        } finally {
            DB::setDefaultConnection($previous);
            app()->forgetInstance('db.schema');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('key_length_probe');
            DB::purge('key_length_state');
        }

        $columns = [];
        $indexes = [];

        foreach (array_column($queries, 'query') as $sql) {
            if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
                foreach ($this->splitTopLevel($match[2]) as $definition) {
                    if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                        $columns[$match[1]][$column[1]] = $this->describeColumn($column[2]);
                    }
                }

                continue;
            }

            // Columns added later (`Schema::table(…)->…`) and columns redefined
            // by `->change()`, which MySQL compiles to `modify`. Both overwrite
            // what the create-table statement said, so the last word wins —
            // that is the shape the index is finally built on. Without these
            // the schema measured would be the one the create migrations
            // described, and brand_id, this addon's whole tenant boundary,
            // arrives exactly this way.
            if (preg_match('/^alter table `(\w+)` ((?:add|modify) .+)$/', $sql, $match)) {
                foreach ($this->splitTopLevel($match[2]) as $definition) {
                    if (preg_match('/^(?:add|modify) `(\w+)` (.+)$/', trim($definition), $column)) {
                        $columns[$match[1]][$column[1]] = $this->describeColumn($column[2]);
                    }
                }
            }

            // Keyed by table and name, so an index that is rebuilt later in the
            // chain counts once, in the shape the last migration left it —
            // which is the shape the server ends up holding.
            if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
                $indexes[$match[1].'.'.$match[3]] = [
                    'table' => $match[1],
                    'name' => $match[3],
                    'unique' => $match[2] === 'unique',
                    'columns' => array_map(
                        fn ($column) => trim($column, ' `'),
                        explode(',', $match[4])
                    ),
                ];
            }

            if (preg_match('/^alter table `(\w+)` drop index `(\w+)`$/', $sql, $match)) {
                unset($indexes[$match[1].'.'.$match[2]]);
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

/**
 * Compiles against MySQL's grammar, answers questions from a real database.
 *
 * Everything that writes goes to the probe connection and is measured.
 * Everything that reads is delegated, because the probe has nothing to read:
 * it has no schema, and `pretend()` would answer "empty" to every question a
 * migration asks about the one it is modifying.
 */
class ProbeSchemaBuilder extends \Illuminate\Database\Schema\MySqlBuilder
{
    public function __construct(
        \Illuminate\Database\Connection $probe,
        private \Illuminate\Database\Connection $state,
    ) {
        parent::__construct($probe);
    }

    public function hasTable($table)
    {
        return $this->state->getSchemaBuilder()->hasTable($table);
    }

    public function hasColumn($table, $column)
    {
        return $this->state->getSchemaBuilder()->hasColumn($table, $column);
    }

    public function hasColumns($table, $columns)
    {
        return $this->state->getSchemaBuilder()->hasColumns($table, $columns);
    }

    public function getTables($schema = null)
    {
        return $this->state->getSchemaBuilder()->getTables();
    }

    public function getColumns($table)
    {
        return $this->state->getSchemaBuilder()->getColumns($table);
    }

    public function getIndexes($table)
    {
        return $this->state->getSchemaBuilder()->getIndexes($table);
    }
}
