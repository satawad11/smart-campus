<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COM-03: versioned, validated node runtime settings with rollback.
 * Append-only: a rollback adds a new revision copying an older one.
 * ai_nodes.applied_config_revision is what the node reports it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_config_revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ai_node_id')->constrained('ai_nodes')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('settings');
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('rolled_back_from_revision')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['ai_node_id', 'revision']);
        });

        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->unsignedInteger('applied_config_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->dropColumn('applied_config_revision');
        });
        Schema::dropIfExists('ai_node_config_revisions');
    }
};
