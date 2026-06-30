<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            // e.g. created, updated, enabled, disabled, deleted, reverted, imported
            $table->string('action');
            $table->string('user_id')->nullable();
            $table->string('user_label')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['automation_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_audit_logs');
    }
};
