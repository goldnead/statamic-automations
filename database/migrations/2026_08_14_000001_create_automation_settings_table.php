<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that an operator changed from the Control Panel.
 *
 * The table holds *overrides only*, one row per changed key — not a copy of the
 * config file. That is what makes an untouched install behave exactly as it did
 * before this table existed, and what lets a key that is never edited keep
 * following the package default when the default changes in a later release.
 * A settings row that mirrored every key would freeze the shipped defaults at
 * whatever they were on the day somebody first opened the screen.
 *
 * Deliberately **not** brand-scoped, unlike every other table in this addon.
 * These are properties of the installation: which queue the jobs go on, how long
 * runs are kept, which keys are redacted before a payload is written down. A
 * queue name that differed per brand would mean the worker draining one brand's
 * jobs was not draining another's, with nothing on screen to say so.
 *
 * `key` is the dotted path under `automations.` (`runs.prune_after_days`), so a
 * row says where it applies without a mapping table in between. `value` is JSON
 * because the values are not all scalars — `security.redact_keys` is a list —
 * and because a `null` that means "same as the default" has to survive the
 * round trip as null rather than as an empty string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_settings');
    }
};
