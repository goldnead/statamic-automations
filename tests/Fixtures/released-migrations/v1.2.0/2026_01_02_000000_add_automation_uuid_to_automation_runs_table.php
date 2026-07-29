<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs migration drift for existing installs.
 *
 * `automation_runs.automation_uuid` was originally added *inline* to the
 * create-migration (2026_01_01_000004). Installs that had already run the older
 * create-migration never receive the column on upgrade, which 500s the
 * Automations index page with "no such column: automation_uuid".
 *
 * This stand-alone, idempotent alter-migration back-fills the column + index on
 * legacy tables. Fresh installs already have it from the create-migration, so
 * the `hasColumn` guard makes this a safe no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_runs')) {
            return;
        }

        if (Schema::hasColumn('automation_runs', 'automation_uuid')) {
            return;
        }

        Schema::table('automation_runs', function (Blueprint $table) {
            // Canonical reference for flat-file definitions (no DB row).
            $table->string('automation_uuid')->nullable()->index()->after('automation_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('automation_runs') || ! Schema::hasColumn('automation_runs', 'automation_uuid')) {
            return;
        }

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropIndex(['automation_uuid']);
            $table->dropColumn('automation_uuid');
        });
    }
};
