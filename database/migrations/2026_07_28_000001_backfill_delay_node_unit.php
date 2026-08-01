<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back-fills the `unit` key on Delay nodes saved before 1.5.2.
 *
 * Until 1.5.2 the config panel *rendered* the schema default ("Minutes")
 * without ever writing it into the model, so a saved Delay node has an
 * `amount` and no `unit`. 1.5.2 fixed the writing side, but left the rows
 * that were already on disk: they keep showing "This field is required."
 * under a visibly pre-filled select until somebody opens the node and
 * re-saves it. This migration finishes the job for existing data.
 *
 * The value written is `minutes` because that is what `DelayNode::execute()`
 * already falls back to (`$config['unit'] ?? 'minutes'`, plus the `default =>`
 * arm of the unit match). The back-fill therefore records the behaviour these
 * nodes have had all along — it does not choose a new one. Any other value
 * would silently change how long existing automations wait.
 *
 * Scope, deliberately narrow:
 *  - only rows with `type = 'delay'`
 *  - only rows whose config has no usable `unit`
 *  - every other key in the config is decoded and re-encoded untouched
 *  - `updated_at` is left alone: this is a repair, not a user edit, and the
 *    column should keep answering "when did a human last change this node?"
 *
 * Multi-brand: this uses the query builder, not the AutomationNode model, on
 * purpose. The model carries the fail-closed `HasBrand` global scope; a
 * migration runs with no brand in context, so the model would match zero rows
 * and quietly skip every tenant. Brand isolation is a request-time boundary —
 * a migration runs beneath it, over the whole table, once. Nothing is read
 * from one brand and written to another: each row is completed from its own
 * config, and `brand_id` is neither read nor written.
 *
 * Flat-file installs (`automations.storage.driver = flatfile`) keep their
 * nodes in YAML and are not covered here; there is no table to migrate. Those
 * nodes still need the one-time re-save described in the 1.5.2 notes.
 */
return new class extends Migration
{
    /** The unit DelayNode::execute() already falls back to when none is stored. */
    private const FALLBACK_UNIT = 'minutes';

    public function up(): void
    {
        if (! Schema::hasTable('automation_nodes')) {
            return;
        }

        DB::table('automation_nodes')
            ->where('type', 'delay')
            ->orderBy('id')
            ->chunkById(500, function ($nodes) {
                foreach ($nodes as $node) {
                    $config = $this->decodeConfig($node->config);

                    if ($config === null) {
                        // Unparseable JSON — leave it exactly as found rather
                        // than rewrite something we did not understand.
                        continue;
                    }

                    $unit = $config['unit'] ?? null;

                    if (is_string($unit) && $unit !== '') {
                        continue;
                    }

                    $config['unit'] = self::FALLBACK_UNIT;

                    DB::table('automation_nodes')
                        ->where('id', $node->id)
                        ->update(['config' => json_encode($config)]);
                }
            });
    }

    /**
     * No down().
     *
     * Reversing this would mean deleting `unit` from Delay node configs — and
     * the migration cannot tell the rows it wrote apart from the ones a user
     * has since edited by hand. A rollback would therefore strip deliberate
     * "hours" / "days" settings and re-break every node it touched, to restore
     * a state whose only property was being broken. The forward direction is
     * additive, idempotent and behaviour-preserving; there is nothing to undo.
     */
    public function down(): void
    {
        // Intentionally empty. See above.
    }

    /**
     * @return array<string, mixed>|null null when the stored JSON is unusable
     */
    private function decodeConfig(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
};
