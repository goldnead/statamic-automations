<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('node_key')->index();
            $table->string('type')->index();
            $table->string('label')->nullable();
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->json('config')->nullable();
            $table->boolean('disabled')->default(false);
            $table->timestamps();

            $table->unique(['automation_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_nodes');
    }
};
