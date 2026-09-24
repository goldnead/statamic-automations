<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Dienst, den ein Kunde im CP anlegt: Basis-URL, Auth und Zugangsdaten.
 *
 * In der Datenbank und nicht in einer YAML-Datei, weil Zugangsdaten nie in
 * Dateien gehoeren, die ins Repo gehen koennen. `auth_config` ist ueber den
 * Cast `encrypted:array` verschluesselt; deshalb `text` und kein `json` — ein
 * Chiffrat ist kein gueltiges JSON, und MySQL wuerde es in einer JSON-Spalte
 * ablehnen.
 *
 * Der Handle ist je Marke eindeutig, wie bei `automations`: er steht in jedem
 * Baustein-Handle `connection.<verbindung>.<operation>`, und zwei Marken duerfen
 * beide einen `slack` haben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_connections', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('brand_id')->default(0)->index();

            $table->string('handle');
            $table->string('name');
            $table->string('base_url', 2048);

            // none | header | bearer | basic
            $table->string('auth_type', 32)->default('none');
            $table->text('auth_config')->nullable();

            // Nicht geheim: steht im CP im Klartext.
            $table->json('default_headers')->nullable();
            $table->unsignedSmallInteger('timeout')->default(15);
            $table->string('test_path', 1024)->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'handle'], 'automation_connections_brand_handle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_connections');
    }
};
