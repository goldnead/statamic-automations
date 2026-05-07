<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('handle')->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('created_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
