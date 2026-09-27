<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operationen, die mehr als JSON sprechen (2.23.0).
 *
 * Alle neuen Spalten sind nullable und ohne Vorgabe: eine bestehende
 * Operation liest `null` als "wie bisher" (JSON-Body aus key_value, Antwort
 * automatisch erkannt, keine eigenen Kopfzeilen). Nichts wird umgeschrieben.
 *
 * `method` wird breiter: jede Methode, die RFC 7230 als Token zulaesst, also
 * auch REPORT, PROPFIND oder MKCALENDAR (10 Zeichen, die alte Spalte hatte 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_connection_operations', function (Blueprint $table) {
            // form_json (null) | raw
            $table->string('body_mode', 16)->nullable();
            $table->string('content_type', 255)->nullable();
            $table->text('raw_body')->nullable();
            // key_value, Werte nehmen {{ input.x }}.
            $table->json('headers')->nullable();
            // auto (null) | json | xml | text
            $table->string('response_format', 8)->nullable();
        });

        Schema::table('automation_connection_operations', function (Blueprint $table) {
            $table->string('method', 32)->default('GET')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('automation_connection_operations')) {
            return;
        }

        Schema::table('automation_connection_operations', function (Blueprint $table) {
            $table->dropColumn(['body_mode', 'content_type', 'raw_body', 'headers', 'response_format']);
        });
    }
};
