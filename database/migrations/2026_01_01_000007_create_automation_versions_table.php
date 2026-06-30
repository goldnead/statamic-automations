<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('label')->nullable();
            // Full snapshot of the automation graph (meta + nodes + edges).
            $table->json('snapshot');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'version']);
            $table->index('automation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_versions');
    }
};
