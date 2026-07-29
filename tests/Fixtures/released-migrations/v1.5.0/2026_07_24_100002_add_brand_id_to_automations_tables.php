<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1 Brand-Scoping for Automations.
 *
 * Adds a `brand_id` to every stateful automations table so the brand-context
 * foundation (goldnead/statamic-brand-context) can isolate data per brand.
 *
 * - The root table (`automations`) carries brand_id as its own scoping column.
 * - Run / node-run / schedule / audit child tables carry brand_id DENORMALIZED
 *   (query-time defense: every read filters on brand_id instead of trusting a
 *   join not to be forgotten).
 * - All existing rows are backfilled onto the default brand ("Bestand = default").
 * - The global `automations.handle` unique becomes `(brand_id, handle)` so the
 *   same handle may exist independently in different brands. Random uuid
 *   uniques stay global.
 *
 * Schema is identical in single- and multi-brand mode; enabling multi-brand
 * later needs no further migration.
 */
return new class extends Migration
{
    /** Every stateful automations table gets a brand_id. */
    private array $tables = [
        'automations',
        'automation_nodes',
        'automation_edges',
        'automation_runs',
        'automation_node_runs',
        'automation_scheduled_jobs',
        'automation_audit_logs',
    ];

    /**
     * Child table => [parent table, foreign key on the child].
     * Used to refine the denormalized brand_id from the parent row.
     */
    private array $children = [
        'automation_nodes' => ['automations', 'automation_id'],
        'automation_edges' => ['automations', 'automation_id'],
        'automation_runs' => ['automations', 'automation_id'],
        'automation_node_runs' => ['automation_runs', 'automation_run_id'],
        'automation_scheduled_jobs' => ['automations', 'automation_id'],
        'automation_audit_logs' => ['automations', 'automation_id'],
    ];

    public function up(): void
    {
        // 1. Add nullable brand_id + index everywhere.
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('brand_id')->nullable()->index();
            });
        }

        // 2. Backfill. Existing data belongs to the default brand.
        $defaultId = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->min('id');

        if ($defaultId !== null) {
            // Baseline: guarantee every row (incl. flat-file runs with a null
            // automation_id and global audit entries) is stamped, then refine
            // children from their parent.
            foreach ($this->tables as $name) {
                DB::table($name)->whereNull('brand_id')->update(['brand_id' => $defaultId]);
            }

            foreach ($this->children as $child => [$parent, $fk]) {
                DB::statement(
                    "UPDATE {$child} SET brand_id = COALESCE("
                    ."(SELECT p.brand_id FROM {$parent} p WHERE p.id = {$child}.{$fk}), brand_id)"
                );
            }
        }

        // 3. Unique rework — the handle identifier becomes brand-scoped.
        Schema::table('automations', function (Blueprint $table): void {
            $table->dropUnique(['handle']);
            $table->unique(['brand_id', 'handle']);
        });
    }

    public function down(): void
    {
        // Revert the handle identifier to un-scoped. We deliberately restore a
        // plain INDEX (not a global unique): once multi-brand data exists the
        // same handle can legitimately live in several brands, so re-adding a
        // global `unique(handle)` would fail on that data. An index keeps the
        // rollback safe and idempotent (also under the test harness, where
        // Testbench runs down() on live rows at teardown).
        Schema::table('automations', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'handle']);
            $table->index('handle');
        });

        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_brand_id_index');
                $table->dropColumn('brand_id');
            });
        }
    }
};
