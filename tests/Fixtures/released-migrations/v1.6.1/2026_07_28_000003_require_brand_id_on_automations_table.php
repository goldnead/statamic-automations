<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `automations.brand_id` NOT NULL, so the handle unique actually holds.
 *
 * Since 1.5.0 the handle is unique per brand: `unique(brand_id, handle)`. The
 * column it leads with was added nullable, and a SQL unique does not constrain
 * NULL — on any engine. Two rows that differ only by a NULL in an indexed
 * column are both accepted, and there is no limit to how many. So for every
 * automations row without a brand_id, the one identifier this addon promises
 * to keep unique was not constrained at all: `handle` could repeat freely, and
 * `Automation::where('handle', …)->first()` would return whichever row the
 * engine happened to reach first.
 *
 * The models stamp brand_id on create (the HasBrand trait), which is why the
 * hole never opened in normal use. It is reachable from everything that writes
 * the table without going through Eloquent — a raw insert, an upsert, an
 * import, a data fix run from tinker — and a constraint that depends on every
 * future writer remembering something is not a constraint.
 *
 * `2026_07_24_100002` now tightens the column when it creates it, which helps
 * new installations only: a migration that has already run does not run again.
 * This one is for the installations that are already on 1.5.x, and it is
 * idempotent — a no-op on a fresh install, where the column arrives NOT NULL.
 *
 * Only `automations` is tightened. The denormalized brand_id on the child
 * tables stays nullable: none of them carries a unique, and changing a
 * column's nullability on MySQL rebuilds the table with ALGORITHM=COPY. That
 * is a fair price on `automations`, which holds one row per automation, and
 * the wrong one on `automation_runs`, which grows without bound.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automations') || ! Schema::hasColumn('automations', 'brand_id')) {
            return;
        }

        if (! $this->brandIdIsNullable()) {
            return; // Already tightened — fresh install, or this ran before.
        }

        $defaultId = $this->defaultBrandId();

        if (DB::table('automations')->whereNull('brand_id')->exists()) {
            if ($defaultId === null) {
                throw new RuntimeException(
                    'automations rows exist without a brand_id and no brand was found to assign them to. '
                    .'Run the goldnead/statamic-brand-context migrations first.'
                );
            }

            $this->disambiguateHandlesCollidingWith($defaultId);

            DB::table('automations')->whereNull('brand_id')->update(['brand_id' => $defaultId]);
        }

        Schema::table('automations', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable(false)->change();
        });
    }

    /**
     * Loosening the column back is the honest reversal, but the duplicate
     * handles this migration renamed are not restored: their original values
     * were never distinguishable from each other, which is the defect being
     * repaired. Renames are written to the log so they can be undone by hand.
     */
    public function down(): void
    {
        if (! Schema::hasTable('automations') || ! Schema::hasColumn('automations', 'brand_id')) {
            return;
        }

        if ($this->brandIdIsNullable()) {
            return;
        }

        Schema::table('automations', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
        });
    }

    private function brandIdIsNullable(): bool
    {
        foreach (Schema::getColumns('automations') as $column) {
            if (($column['name'] ?? null) === 'brand_id') {
                return (bool) ($column['nullable'] ?? true);
            }
        }

        return true;
    }

    private function defaultBrandId(): ?int
    {
        if (! Schema::hasTable('brands')) {
            return null;
        }

        $id = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->min('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Frees up handles before the backfill can collide on them.
     *
     * The unique never applied to NULL-brand rows, so two of them may share a
     * handle with each other or with a row that already belongs to the default
     * brand. Stamping them all with the same brand_id would then hit the unique
     * and abort the migration. The rows are renamed rather than deleted: an
     * automation is somebody's work, and a suffixed handle is visible and
     * fixable, whereas a deleted flow is neither.
     */
    private function disambiguateHandlesCollidingWith(int $defaultId): void
    {
        $taken = DB::table('automations')
            ->where('brand_id', $defaultId)
            ->pluck('handle')
            ->filter()
            ->flip()
            ->map(fn () => true)
            ->all();

        DB::table('automations')
            ->whereNull('brand_id')
            ->orderBy('id')
            ->each(function ($row) use (&$taken): void {
                $handle = (string) $row->handle;

                if ($handle !== '' && ! isset($taken[$handle])) {
                    $taken[$handle] = true;

                    return;
                }

                $candidate = $handle === '' ? 'automation-'.$row->id : $handle.'-'.$row->id;
                $suffix = 2;

                while (isset($taken[$candidate])) {
                    $candidate = ($handle === '' ? 'automation-'.$row->id : $handle.'-'.$row->id).'-'.$suffix++;
                }

                DB::table('automations')->where('id', $row->id)->update(['handle' => $candidate]);
                $taken[$candidate] = true;

                info(sprintf(
                    '[statamic-automations] Renamed duplicate automation handle "%s" to "%s" (id %d) '
                    .'so brand_id could be made NOT NULL. The old handle was never unique.',
                    $handle,
                    $candidate,
                    $row->id
                ));
            });
    }
};
