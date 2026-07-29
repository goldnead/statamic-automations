<?php

use Goldnead\StatamicAutomations\Tests\Concerns\DrivesMigrationsOnAnIsolatedDatabase;
use Goldnead\StatamicAutomations\Tests\Fixtures\AutomationsDataFixture;

/**
 * `2026_07_24_100002_add_brand_id_to_automations_tables` has to survive being
 * run a second time, on a database its own first run left halfway through.
 *
 * The migration adds `brand_id` to seven tables and then has two places left
 * where it can abort: the RuntimeException it raises when `automations` still
 * holds rows with no brand to put them on, and the rework of the handle unique,
 * where the drop and the create are two separate statements. Neither MySQL nor
 * SQLite rolls DDL back, and a migration that throws is not written to the
 * `migrations` table — so an aborted run leaves a database that is partly
 * migrated and a bookkeeping table that says the migration never happened.
 *
 * The only move available to whoever hits that is `php artisan migrate` again.
 * Unguarded, the second run does not get as far as the problem: it dies at the
 * very first statement on `duplicate column name: brand_id`, an error about
 * step 1 that describes nothing that is actually wrong and points whoever reads
 * it at the wrong end of the file. Correcting the order of the statements would
 * fix the next install and leave that one exactly as broken as it is.
 *
 * These two cases are therefore about `up()` being re-runnable, not about it
 * being correctly ordered. They run against the isolated connection from
 * {@see DrivesMigrationsOnAnIsolatedDatabase} rather than the suite's own,
 * because the suite's database has been migrated to head before the test body
 * starts, and a migration that has already run is the one state that cannot be
 * interrupted. The trait rather than
 * {@see \Goldnead\StatamicAutomations\Tests\MigrationPathTestCase} because
 * `tests/Feature` is already bound to the ordinary TestCase and Pest allows a
 * file only one of those.
 */
uses(DrivesMigrationsOnAnIsolatedDatabase::class);

/**
 * The migration under test.
 */
const BRAND_MIGRATION = '2026_07_24_100002_add_brand_id_to_automations_tables';

/**
 * The schema as it stood immediately before brand scoping — every migration
 * that sorts ahead of the one under test, and nothing after it — with a
 * generation of real data in it.
 *
 * Taken from the live migration directory rather than from a copy, so this
 * keeps describing "everything before the brand migration" as the directory
 * grows.
 */
beforeEach(function (): void {
    $this->resetIsolatedDatabase();

    foreach ($this->migrationFilesIn($this->currentMigrations()) as $file) {
        if (basename($file, '.php') >= BRAND_MIGRATION) {
            break;
        }

        $this->migratePath($file);
    }

    (new AutomationsDataFixture($this->isolated()))->seed(0);

    expect($this->ranMigrations())->not->toContain(BRAND_MIGRATION);
});

afterEach(function (): void {
    $this->dropIsolatedSqliteFile();
});

it('finishes an interrupted run instead of failing on the column it already added', function (): void {
    // The wreckage of a first attempt: step 1 got through part of its list and
    // the run died before anything else happened. Three tables carry the
    // column, four do not, and nothing is recorded in `migrations` — which is
    // the state an operator finds, not a state anybody chose.
    foreach (['automations', 'automation_nodes', 'automation_edges'] as $table) {
        $this->isolatedSchema()->table($table, function ($blueprint): void {
            $blueprint->unsignedBigInteger('brand_id')->nullable()->index();
        });
    }

    // The retry. It has to complete, not report the column it added itself.
    $this->migratePath($this->currentMigrations().'/'.BRAND_MIGRATION.'.php');

    expect($this->ranMigrations())->toContain(BRAND_MIGRATION);

    // And it has to have done the work, not merely survived. Every table
    // carries the column, every row carries a brand, and the handle unique is
    // the brand-scoped one — proven by writing the row it must refuse.
    foreach (AutomationsDataFixture::tables() as $table) {
        expect($this->isolatedSchema()->hasColumn($table, 'brand_id'))
            ->toBeTrue("{$table} was left without a brand_id");
    }

    expect($this->isolated()->table('automations')->whereNull('brand_id')->count())->toBe(0);

    expect($this->duplicateHandleIsAccepted(AutomationsDataFixture::handleProbe(0)))
        ->toBeFalse('the handle unique does not bite after the interrupted run was picked back up');
});

it('is a no-op the second time it runs over the same rows', function (): void {
    $fixture = new AutomationsDataFixture($this->isolated());

    $this->migratePath($this->currentMigrations().'/'.BRAND_MIGRATION.'.php');

    $before = $fixture->counts();

    // Forget that it ran. This is not a contrivance: an aborted run is never
    // recorded, so from `migrate`'s point of view this is exactly the state a
    // half-migrated install is in, minus the damage.
    $this->isolated()->table('migrations')->where('migration', BRAND_MIGRATION)->delete();

    $this->migratePath($this->currentMigrations().'/'.BRAND_MIGRATION.'.php');

    expect($this->ranMigrations())->toContain(BRAND_MIGRATION);

    // Nothing gained, nothing lost, and the constraint still holds.
    expect($fixture->counts())->toBe($before);

    expect($this->isolated()->table('automations')->whereNull('brand_id')->count())->toBe(0);

    expect($this->duplicateHandleIsAccepted(AutomationsDataFixture::handleProbe(0)))
        ->toBeFalse('the handle unique does not bite after the migration ran twice');
});
