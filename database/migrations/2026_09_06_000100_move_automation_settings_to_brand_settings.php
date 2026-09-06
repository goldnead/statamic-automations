<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the Control Panel overrides from this addon's own table into the
 * suite's shared one.
 *
 * `automation_settings` was this addon's private key/value store, and it had a
 * defect its own migration named out loud: no brand column. On a multi-brand
 * install two brands shared one row for the queue name and the retention, with
 * nothing on screen to say so. `brand_settings` is the same store done once for
 * the whole suite, and it is scoped by brand.
 *
 * **Every row lands on the default brand.** That is the only honest reading of
 * a row that was written when brands did not apply: it was set by whoever
 * administers the installation, and the default brand is the one an install
 * has before anybody adds a second. Spreading a value across every brand would
 * invent a decision nobody made, and picking the *current* brand would depend
 * on which request happened to run the migration.
 *
 * **`automation_settings` is not dropped here.** It stays for one minor
 * version. A `migrate:rollback` after an upgrade puts the old code back, and
 * the old code reads that table — dropping it in the same release that stops
 * using it means a rollback comes up with every setting silently back at its
 * packaged default. Deleting a table is cheap and can wait; a rollback that
 * loses an operator's configuration cannot be undone. The drop belongs in the
 * next minor, together with the `statamic-automations.settings` redirect route.
 *
 * **Idempotent.** `insertOrIgnore` against `brand_settings_unique`, so a rerun
 * — and the stepwise walk in `tests/Migrations/MigrationsWithExistingDataTest`
 * is exactly that — adds nothing and, more importantly, does not overwrite a
 * value the operator has changed on the new screen since.
 */
return new class extends Migration
{
    public function up(): void
    {
        $brandId = $this->defaultBrandId();

        if ($brandId === null || ! Schema::hasTable('automation_settings')) {
            return;
        }

        $now = now();

        // Raw query builder rather than the models: `BrandSetting` carries
        // `HasBrand`, whose global scope resolves the *current* brand — which
        // outside a request is nothing at all, and inside one is whichever
        // brand the operator happens to be looking at. A migration writes to
        // the brand it decided on, not to the one the session is on.
        $rows = DB::table('automation_settings')->get(['key', 'value', 'created_at', 'updated_at']);

        if ($rows->isEmpty()) {
            return;
        }

        DB::table('brand_settings')->insertOrIgnore(
            $rows->map(fn ($row) => [
                'brand_id' => $brandId,
                'namespace' => 'automations',
                'key' => $row->key,
                // Handed over as the driver returned it, not decoded and
                // re-encoded. Both columns are JSON and hold the same
                // document; a round trip through PHP would turn a stored
                // integer into a float on some drivers and would have to
                // reproduce Laravel's exact encoding flags to avoid rewriting
                // strings that contain a slash or an umlaut.
                'value' => $row->value,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ])->all()
        );
    }

    /**
     * Take back exactly what was carried over, and nothing else.
     *
     * Deleting every `automations` row would also delete the settings an
     * operator has made on the new screen since the upgrade — rows this
     * migration never touched. So the delete is restricted to the keys that
     * are still sitting in the old table, which is precisely the set `up()`
     * inserted.
     */
    public function down(): void
    {
        $brandId = $this->defaultBrandId();

        if ($brandId === null || ! Schema::hasTable('automation_settings')) {
            return;
        }

        $keys = DB::table('automation_settings')->pluck('key');

        if ($keys->isEmpty()) {
            return;
        }

        DB::table('brand_settings')
            ->where('brand_id', $brandId)
            ->where('namespace', 'automations')
            ->whereIn('key', $keys)
            ->delete();
    }

    /**
     * The brand the overrides belong to, or null if there is none to speak of.
     *
     * Null is a real outcome, not a failure: an install whose brand table has
     * not been created or seeded yet has nothing to attach a setting to, and
     * has no rows to carry over either. Refusing to guess is what keeps this
     * migration safe to rerun once the brand does exist.
     *
     * Mirrors `Brand::default()` rather than calling it, for the same reason
     * the writes above avoid the model: a migration must not depend on a global
     * scope, and it must keep working if that class is refactored later.
     */
    protected function defaultBrandId(): ?int
    {
        if (! Schema::hasTable('brands') || ! Schema::hasTable('brand_settings')) {
            return null;
        }

        $handle = config('brand-context.default_handle', 'default');

        $id = DB::table('brands')->where('handle', $handle)->value('id')
            ?? DB::table('brands')->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }
};
