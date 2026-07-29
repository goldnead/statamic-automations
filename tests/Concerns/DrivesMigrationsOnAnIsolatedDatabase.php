<?php

namespace Goldnead\StatamicAutomations\Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The machinery for migrating a database by hand, from any released schema
 * forward.
 *
 * The rest of the suite runs against a database that `RefreshDatabase` has
 * already migrated to head, which is the one shape a migration can never be
 * wrong about. Everything here needs the opposite: an empty database, an
 * arbitrary earlier release installed into it, rows put in, and then the
 * migrations run one at a time with the tables no longer empty.
 *
 * That cannot share the suite's connection. `RefreshDatabase` wraps every test
 * in a transaction, and DDL under MySQL commits implicitly — a `migrate` run
 * inside that transaction would end it and leak its tables into every test
 * that followed. So these tests get a connection of their own, outside
 * anything the trait manages: a temp-file SQLite database by default, and a
 * second throwaway schema beside the configured one when the suite is pointed
 * at MySQL (see phpunit.mysql.xml). It is torn down between tests either way.
 *
 * This lives in a trait rather than only in
 * {@see \Goldnead\StatamicAutomations\Tests\MigrationPathTestCase} because a
 * Pest file can be given a trait on top of the test case its directory is
 * already bound to, but not a second test case. `tests/Migrations` gets the
 * base class; a migration test that belongs in `tests/Feature` for other
 * reasons gets the trait.
 */
trait DrivesMigrationsOnAnIsolatedDatabase
{
    /**
     * The name of the isolated connection these tests migrate.
     */
    protected string $isolatedConnectionName = 'migration_path';

    /**
     * Empty database, brand-context installed, nothing of this addon's own.
     *
     * `brands` is a hard precondition, not a convenience: the brand-scoping
     * migration backfills every row onto the default brand and refuses to
     * build the brand-scoped handle unique if it cannot find one.
     */
    protected function resetIsolatedDatabase(): void
    {
        $this->registerIsolatedConnection();

        if (! $this->onMysql()) {
            $this->dropIsolatedSqliteFile();
            touch($this->isolatedSqlitePath());
        } else {
            // A server-level handle with no database selected, used for
            // nothing but `create database`. Issuing that on the suite's own
            // connection would implicitly commit the transaction
            // RefreshDatabase is holding open, and every test after this one
            // would roll back into nothing.
            DB::connection($this->isolatedConnectionName.'_server')->statement(
                'create database if not exists `'.$this->isolatedMysqlDatabase()
                .'` character set utf8mb4 collate utf8mb4_unicode_ci'
            );

            DB::purge($this->isolatedConnectionName.'_server');
        }

        DB::purge($this->isolatedConnectionName);

        Schema::connection($this->isolatedConnectionName)->dropAllTables();

        DB::purge($this->isolatedConnectionName);

        $this->migratePath($this->brandContextMigrations());
    }

    /**
     * Put the isolated connection into the config.
     *
     * Done here rather than in `defineEnvironment` so that a test which only
     * borrows the trait does not have to override the environment hook of the
     * test case its directory is bound to. Connections are resolved from the
     * config lazily, so registering one mid-test is no different from having
     * declared it up front.
     */
    protected function registerIsolatedConnection(): void
    {
        config([
            'database.connections.'.$this->isolatedConnectionName => $this->isolatedConnection(),
            'database.connections.'.$this->isolatedConnectionName.'_server' => [
                ...$this->isolatedConnection(),
                'database' => null,
            ],
        ]);
    }

    /**
     * Mirrors TestCase::defineEnvironment(), so these tests exercise the same
     * engine the rest of the run does — including the MySQL run, where the
     * index and nullability rules that SQLite does not have are the whole
     * point. Note the switch is `AUTOMATIONS_TEST_DB`, the variable this
     * addon's phpunit.mysql.xml sets; there is no `DB_DRIVER` here.
     *
     * @return array<string, mixed>
     */
    protected function isolatedConnection(): array
    {
        if (! $this->onMysql()) {
            return [
                'driver' => 'sqlite',
                'database' => $this->isolatedSqlitePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => $this->isolatedMysqlDatabase(),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function onMysql(): bool
    {
        return env('AUTOMATIONS_TEST_DB', 'sqlite') === 'mysql';
    }

    protected function isolatedMysqlDatabase(): string
    {
        return env('DB_DATABASE', 'automations_test').'_migration_path';
    }

    protected function isolatedSqlitePath(): string
    {
        return sys_get_temp_dir().'/automations-migration-path-'.getmypid().'.sqlite';
    }

    protected function brandContextMigrations(): string
    {
        return __DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations';
    }

    protected function dropIsolatedSqliteFile(): void
    {
        if ($this->onMysql()) {
            return;
        }

        DB::purge($this->isolatedConnectionName);

        if (file_exists($this->isolatedSqlitePath())) {
            @unlink($this->isolatedSqlitePath());
        }
    }

    /**
     * Run every not-yet-run migration in a directory (or a single file)
     * against the isolated connection. Failures are not swallowed: the point
     * of these tests is what happens when one throws.
     */
    protected function migratePath(string $path): void
    {
        Artisan::call('migrate', [
            '--database' => $this->isolatedConnectionName,
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Run the migrations in a directory one file at a time, handing control
     * back between each so a caller can put rows in first.
     *
     * @param  callable(string): void|null  $before  receives the migration name
     */
    protected function migrateStepwise(string $path, ?callable $before = null): void
    {
        foreach ($this->migrationFilesIn($path) as $file) {
            if ($before) {
                $before(basename($file, '.php'));
            }

            $this->migratePath($file);
        }
    }

    /**
     * @return list<string>
     */
    protected function migrationFilesIn(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php') ?: [];

        sort($files);

        return $files;
    }

    protected function releasedMigrations(string $version): string
    {
        return __DIR__.'/../Fixtures/released-migrations/'.$version;
    }

    protected function currentMigrations(): string
    {
        return __DIR__.'/../../database/migrations';
    }

    protected function isolated(): Connection
    {
        return DB::connection($this->isolatedConnectionName);
    }

    protected function isolatedSchema(): Builder
    {
        return Schema::connection($this->isolatedConnectionName);
    }

    /**
     * The migration names the isolated database has recorded as run.
     *
     * @return list<string>
     */
    protected function ranMigrations(): array
    {
        if (! $this->isolatedSchema()->hasTable('migrations')) {
            return [];
        }

        return $this->isolated()->table('migrations')->pluck('migration')->all();
    }

    /**
     * Whether a second automation with the same handle, in the same brand, is
     * accepted by the database.
     *
     * This is the assertion the migration tests exist for, and it is
     * deliberately behavioural. "The migration ran" and "the constraint is
     * there" are not the same statement, and confusing the two is exactly how
     * a release ships with the handle unique dropped and not replaced. An
     * index by name can exist over the wrong columns, over a nullable column,
     * or not bite at all; the only thing that settles it is trying to write
     * the row the constraint is supposed to refuse.
     *
     * The probe row is a copy of a real fixture row with a fresh `uuid`, so
     * the only thing that can refuse it is the handle constraint — not the
     * global uuid unique, and not a NOT NULL on some unrelated column.
     */
    protected function duplicateHandleIsAccepted(string $handle): bool
    {
        $existing = $this->isolated()->table('automations')->where('handle', $handle)->first();

        if (! $existing) {
            throw new \RuntimeException("No automation with the handle [{$handle}] to duplicate.");
        }

        $row = collect((array) $existing)
            ->except('id')
            ->put('uuid', (string) Str::uuid())
            ->all();

        try {
            $id = $this->isolated()->table('automations')->insertGetId($row);
        } catch (QueryException) {
            return false;
        }

        $this->isolated()->table('automations')->where('id', $id)->delete();

        return true;
    }
}
