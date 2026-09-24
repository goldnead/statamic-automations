<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Operation einer Verbindung — im Builder ein eigener Aktions-Baustein.
 *
 * Keine eigene `brand_id`: die Marke kommt ueber die Verbindung, und der Handle
 * ist nur innerhalb dieser Verbindung eindeutig.
 *
 * `body` ist key_value und kein roher JSON-Text: das Addon baut daraus ein
 * Array und kodiert es selbst, damit ein Anfuehrungszeichen in einem Wert den
 * Body nicht aufbrechen kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_connection_operations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->constrained('automation_connections')
                ->cascadeOnDelete();

            $table->string('handle');
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('method', 8)->default('GET');
            $table->string('path', 2048)->default('/');
            $table->json('query')->nullable();
            $table->json('body')->nullable();
            $table->json('inputs')->nullable();
            $table->json('response_map')->nullable();

            // Abweichend von `send_webhook`: wer eine API anruft, will wissen,
            // ob sie gescheitert ist.
            $table->boolean('fail_on_error_status')->default(true);

            $table->timestamps();

            $table->unique(['connection_id', 'handle'], 'automation_connection_operations_handle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_connection_operations');
    }
};
