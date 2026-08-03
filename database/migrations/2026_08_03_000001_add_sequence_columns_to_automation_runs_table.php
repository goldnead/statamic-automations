<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to `automation_runs`, both of which existing rows survive
 * untouched.
 *
 * **`subject_key`** — who a run is about. Until now a run knew which automation
 * it belonged to and what triggered it, but not whose pass through it this was;
 * the answer was inside the redacted `context` JSON, which is not something any
 * database can index. Nullable, because a trigger whose subject cannot be named
 * (a scheduled sweep, a webhook with no address in it) is a legitimate run and
 * must keep working — the restart policy simply does not apply to it.
 *
 * **Two composite indexes** — the ones the two new readers need, and neither is
 * covered by the single-column indexes already on the table:
 *
 *   - `(automation_uuid, status)` for the funnel. `automation_uuid` alone
 *     narrows to one automation and then scans every run it ever had to bucket
 *     them by status; `status` alone narrows to every failed run in the
 *     install. The pair is the GROUP BY.
 *   - `(automation_uuid, subject_key)` for the restart policy, which asks "has
 *     this person been through this automation before" once per incoming
 *     trigger event. Without it that question is a scan on every subscribe, on
 *     the hot path of the thing the policy exists to make cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_runs')) {
            return;
        }

        if (! Schema::hasColumn('automation_runs', 'subject_key')) {
            Schema::table('automation_runs', function (Blueprint $table) {
                // 191, not the default 255. An address longer than that does
                // not exist in practice, and the number is what keeps the
                // composite below under half of InnoDB's 3072-byte key limit —
                // the margin `IndexKeyLengthTest` requires so the *next*
                // column added to that index does not break the migration.
                $table->string('subject_key', 191)->nullable()->after('trigger_type');
            });
        }

        // Two columns that were declared 255 characters wide and never needed
        // to be. Alone they were only ever a single-column index each and
        // nobody noticed; put into a composite they add up to 2040 bytes under
        // utf8mb4, which is two thirds of what InnoDB allows for one index.
        //
        // `automation_uuid` holds a UUID — 36 characters, always. `status` holds
        // one of seven words, the longest of which is `cancelled`. Narrowing
        // them cannot truncate any value that exists, and it is what makes the
        // indexes below fit with room to spare (144 + 80 bytes).
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->string('automation_uuid', 36)->nullable()->change();
            $table->string('status', 20)->default('queued')->change();
        });

        // Named explicitly. Laravel's generated name for the second one is
        // `automation_runs_automation_uuid_subject_key_index` — 52 characters,
        // comfortably inside MySQL's 64, but naming them lets down() drop them
        // without guessing, and lets both halves ask whether they exist.
        foreach ([
            'automation_runs_uuid_status_index' => ['automation_uuid', 'status'],
            'automation_runs_uuid_subject_index' => ['automation_uuid', 'subject_key'],
        ] as $name => $columns) {
            if (self::hasIndex($name)) {
                continue;
            }

            Schema::table('automation_runs', function (Blueprint $table) use ($name, $columns) {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('automation_runs')) {
            return;
        }

        // Asked rather than assumed. `migrate:rollback` is not the only caller:
        // a test that simulated a pre-1.2 table by dropping `automation_uuid`
        // has to drop every index naming it first, and would otherwise leave
        // this method dropping an index somebody else already took.
        foreach (['automation_runs_uuid_status_index', 'automation_runs_uuid_subject_index'] as $name) {
            if (! self::hasIndex($name)) {
                continue;
            }

            Schema::table('automation_runs', function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }

        if (Schema::hasColumn('automation_runs', 'subject_key')) {
            Schema::table('automation_runs', function (Blueprint $table) {
                $table->dropColumn('subject_key');
            });
        }

        // The two narrowings are deliberately NOT reversed. Widening a column
        // back is not what a rollback is for, no data depends on it, and doing
        // it would rebuild the table a second time on SQLite for no gain.
    }

    protected static function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('automation_runs') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
