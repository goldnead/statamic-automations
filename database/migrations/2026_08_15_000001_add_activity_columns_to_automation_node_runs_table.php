<?php

use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Support\RunStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives `automation_node_runs` the two facts every activity question starts
 * from: which automation the row belongs to, and whether it came from a test.
 *
 * ── Why the column and not the join ───────────────────────────────────────
 *
 * `automation_node_runs` had no reference to an automation at all. The only
 * route from a node run to its flow was
 * `automation_run_id → automation_runs.automation_uuid`, and `is_test` — which
 * every number on the activity screen has to exclude, exactly as
 * {@see RunStats} already does — lived on
 * the parent as well. So the one query the screen is built around,
 *
 *     "how many people reached each node of THIS automation in the last 30 days,
 *      not counting tests"
 *
 * was a join of the largest table in the schema (one row per node per run)
 * against the second largest, with no index on either side that narrowed it:
 * `automation_node_runs` could only be entered by `automation_run_id`, so the
 * plan had to read every run of the automation first and then fan out. On an
 * install with a year of a daily welcome series that is the whole table.
 *
 * With both columns here, the same question is one index range on one table,
 * and the join disappears from the *filter* — the protocol screen still joins
 * back to `automation_runs`, but only to decorate the twenty-five rows a page
 * has already selected.
 *
 * The precedent is `brand_id`, denormalised onto all seven child tables by
 * `2026_07_24_100002` and argued there as "query-time defense". Both columns
 * copied here have the same property that made that safe: they are decided when
 * the parent run is created and never change afterwards
 * ({@see WorkflowRunner} writes `is_test`
 * once, `automation_uuid` once), so there is no window in which the copy and
 * the original can disagree.
 *
 * ── Widths ────────────────────────────────────────────────────────────────
 *
 * `automation_uuid` is declared 36 rather than the default 255, for the reason
 * `2026_08_03_000001` narrowed the same column on `automation_runs`: it holds a
 * UUID, always, and the width is what keeps the composite below under half of
 * InnoDB's 3072-byte key limit — 144 + 1020 + 8 = 1172 bytes under utf8mb4,
 * which is the margin `IndexKeyLengthTest` requires.
 *
 * `node_key` is deliberately NOT narrowed with it. It is validated `max:255` by
 * both Store- and UpdateAutomationRequest, so a 255-character key is a legal
 * value somebody's install may already hold; narrowing the column would
 * truncate it and break the join back to `automation_nodes.node_key` — silently,
 * on exactly the rows a history screen exists to show.
 *
 * ── The second index ──────────────────────────────────────────────────────
 *
 * `automation_runs` gains `(automation_uuid, status, created_at)` because the
 * funnel now takes a timeframe. The existing `automation_runs_uuid_status_index`
 * is a prefix of it and is left in place: `RunStatsTest` pins its existence, and
 * dropping an index a released version created is a bigger promise than this
 * migration should make.
 */
return new class extends Migration
{
    private const NODE_RUN_INDEX = 'automation_node_runs_uuid_node_created_index';

    private const RUN_INDEX = 'automation_runs_uuid_status_created_index';

    public function up(): void
    {
        if (! Schema::hasTable('automation_node_runs')) {
            return;
        }

        if (! Schema::hasColumn('automation_node_runs', 'automation_uuid')) {
            Schema::table('automation_node_runs', function (Blueprint $table) {
                $table->string('automation_uuid', 36)->nullable()->after('automation_run_id');
            });
        }

        if (! Schema::hasColumn('automation_node_runs', 'is_test')) {
            Schema::table('automation_node_runs', function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->after('status');
            });
        }

        $this->backfill();

        if (! self::hasIndex('automation_node_runs', self::NODE_RUN_INDEX)) {
            Schema::table('automation_node_runs', function (Blueprint $table) {
                $table->index(['automation_uuid', 'node_key', 'created_at'], self::NODE_RUN_INDEX);
            });
        }

        if (Schema::hasTable('automation_runs') && ! self::hasIndex('automation_runs', self::RUN_INDEX)) {
            Schema::table('automation_runs', function (Blueprint $table) {
                $table->index(['automation_uuid', 'status', 'created_at'], self::RUN_INDEX);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['automation_node_runs', self::NODE_RUN_INDEX],
            ['automation_runs', self::RUN_INDEX],
        ] as [$table, $name]) {
            if (Schema::hasTable($table) && self::hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }

        if (! Schema::hasTable('automation_node_runs')) {
            return;
        }

        foreach (['automation_uuid', 'is_test'] as $column) {
            if (Schema::hasColumn('automation_node_runs', $column)) {
                Schema::table('automation_node_runs', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Copy both facts down from the parent run.
     *
     * Both statements are written so a *second* run of this migration is a
     * no-op rather than a correction — neither engine rolls DDL back, and an
     * aborted migration is not recorded as run, so a retry has to meet a
     * database in any state the first attempt could have left it in. That rules
     * out "backfill only when the column was just added": a run that added the
     * column and then died would skip the copy forever.
     *
     * `automation_uuid` is guarded by its own NULL, which is what an un-copied
     * row looks like. `is_test` has no such marker — its default is `false`,
     * which is also a legitimate value — so instead of rewriting every row it
     * only raises the flag on rows whose parent carries it. Test runs are a
     * handful on any install, so this touches almost nothing, and running it
     * twice writes the same 1 twice.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('automation_runs')) {
            return;
        }

        DB::statement(
            'UPDATE automation_node_runs SET automation_uuid = ('
            .'SELECT r.automation_uuid FROM automation_runs r WHERE r.id = automation_node_runs.automation_run_id'
            .') WHERE automation_uuid IS NULL'
        );

        DB::statement(
            'UPDATE automation_node_runs SET is_test = 1 WHERE is_test = 0 AND automation_run_id IN ('
            .'SELECT id FROM automation_runs WHERE is_test = 1'
            .')'
        );
    }

    private static function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
