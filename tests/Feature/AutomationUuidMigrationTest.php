<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guards the drift repair for `automation_runs.automation_uuid`.
 *
 * The column was originally added *inline* to the create-migration
 * (2026_01_01_000004). Installs that had already run the older create-migration
 * never receive it on upgrade, which 500s the Automations index page with
 * "no such column: automation_uuid". The stand-alone alter-migration
 * (2026_01_02_000000) back-fills it idempotently — these tests prove it.
 */
class AutomationUuidMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__
        .'/../../database/migrations/2026_01_02_000000_add_automation_uuid_to_automation_runs_table.php';

    public function test_it_backfills_automation_uuid_on_a_legacy_table(): void
    {
        // Simulate an install created by the OLDER create-migration, before
        // `automation_uuid` was added inline: drop it so the table looks legacy.
        // (Drop the index first — SQLite refuses to drop an indexed column.)
        $this->dropAutomationUuid();

        $this->assertFalse(
            Schema::hasColumn('automation_runs', 'automation_uuid'),
            'Precondition: legacy table must not have automation_uuid.'
        );

        $migration = require self::MIGRATION;
        $migration->up();

        $this->assertTrue(
            Schema::hasColumn('automation_runs', 'automation_uuid'),
            'Migration must add the automation_uuid column.'
        );
    }

    public function test_running_the_migration_is_idempotent(): void
    {
        $migration = require self::MIGRATION;

        // Column already present (fresh install): up() must be a safe no-op.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('automation_runs', 'automation_uuid'));

        // After a manual drop, up() re-adds it — and a second call still no-ops.
        $this->dropAutomationUuid();
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('automation_runs', 'automation_uuid'));
    }

    /**
     * Make the table look like a pre-1.2 install: no `automation_uuid` at all.
     *
     * Every index that mentions the column has to go first, and they are read
     * off the schema rather than listed here. SQLite refuses to drop an indexed
     * column — with an error about the index rather than about the column,
     * which is a slow thing to read backwards — and this list has already grown
     * three times: 1.9 added the funnel composite and the re-entry lookup, and
     * 1.9.1 added the one that carries the date. A hardcoded list makes the
     * NEXT index fail two unrelated tests with a message about SQLite.
     */
    private function dropAutomationUuid(): void
    {
        $indexes = collect(Schema::getIndexes('automation_runs'))
            ->filter(fn (array $index) => in_array('automation_uuid', $index['columns'] ?? [], true))
            ->pluck('name')
            ->all();

        Schema::table('automation_runs', function (Blueprint $table) use ($indexes) {
            foreach ($indexes as $name) {
                $table->dropIndex($name);
            }

            $table->dropColumn('automation_uuid');
        });
    }
}
