<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COM-03: authorised remote operations on AI nodes. Only commands from a
 * fixed allowlist (AiNodeCommandType) are stored; there is no free-form
 * command text, so this can never act as a remote shell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ai_node_id')->constrained('ai_nodes')->restrictOnDelete();
            $table->string('command', 32);
            $table->string('status', 16)->index();
            $table->string('reason', 255)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('result_message', 500)->nullable();
            $table->timestampsTz();

            $table->index(['ai_node_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_node_commands');
    }
};
