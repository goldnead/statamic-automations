<?php

use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the run timestamps to millisecond precision.
 *
 * `started_at` / `finished_at` on runs and node runs were plain `timestamp`
 * columns, so anything faster than a second collapsed onto a single stored
 * instant: two nodes that ran 40 ms apart came back with the same
 * `started_at`, and the only surviving evidence of the difference was
 * `duration_ms`. That is enough to render a duration, and not enough to sort
 * or correlate by point in time.
 *
 * The column widening here is only half the fix. Eloquent serialises dates
 * with the connection's format (`Y-m-d H:i:s` on every driver), so the
 * fraction was being dropped in the model, before the column was ever
 * involved. The models now cast these four attributes through
 * {@see MillisecondDateTime}. Without that
 * cast this migration would change the schema and nothing else.
 *
 * SQLite is skipped on purpose. It has no typed datetime — Laravel maps
 * `timestamp` and `timestamp(3)` both to `datetime`, which SQLite stores as
 * text — so `->change()` there produces a column that is byte-for-byte the
 * one it replaced. What it *does* do is a full table rebuild (create temp,
 * copy every row, drop, rename, recreate the indexes), and these tables grow.
 * Paying a table copy on a growing table for a no-op is the wrong trade, so
 * the migration reports the skip instead of performing it. SQLite installs
 * still get millisecond timestamps: the precision there comes entirely from
 * the string the cast writes, which SQLite stores and returns verbatim.
 *
 * MySQL note for large installs: changing a TIMESTAMP's fractional-seconds
 * precision cannot be done in place — MySQL rebuilds the table with
 * ALGORITHM=COPY and blocks writes for the duration. On a runs table of any
 * size, run this in a maintenance window or pt-online-schema-change it.
 */
return new class extends Migration
{
    private const COLUMNS = ['started_at', 'finished_at'];

    private const TABLES = ['automation_runs', 'automation_node_runs'];

    public function up(): void
    {
        $this->setPrecision(3);
    }

    /**
     * Narrowing back to whole seconds is lossy — every stored fraction is
     * discarded — but it is a schema change, and a migration that cannot be
     * rolled back blocks `migrate:rollback` for everything layered on top of
     * it. The reversal is offered with that cost stated, and it is safe in the
     * sense that matters: the application keeps working, because
     * `duration_ms` is computed before persisting and never depended on the
     * column's precision.
     */
    public function down(): void
    {
        $this->setPrecision(0);
    }

    private function setPrecision(int $precision): void
    {
        if ($this->isSqlite()) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($precision) {
                foreach (self::COLUMNS as $column) {
                    $blueprint->timestamp($column, $precision)->nullable()->change();
                }
            });
        }
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
