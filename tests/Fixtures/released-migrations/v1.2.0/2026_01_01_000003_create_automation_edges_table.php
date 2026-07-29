<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_edges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('from_node_key');
            $table->string('from_output')->default('default');
            $table->string('to_node_key');
            $table->string('to_input')->default('default');
            $table->timestamps();

            $table->index(['automation_id', 'from_node_key']);
            $table->index(['automation_id', 'to_node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_edges');
    }
};
