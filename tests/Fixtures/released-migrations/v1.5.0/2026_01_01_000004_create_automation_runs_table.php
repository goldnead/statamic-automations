<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Nullable + null-on-delete so runs can also reference flat-file
            // definitions (which have no database row). `automation_uuid` is
            // the canonical reference in flat-file mode.
            $table->foreignId('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->string('automation_uuid')->nullable()->index();
            $table->string('trigger_node_key')->nullable();
            $table->string('trigger_type')->nullable()->index();
            $table->string('status')->default('queued')->index();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->index(['automation_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
