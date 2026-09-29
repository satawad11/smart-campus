<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // CAMERA_DOWN, AI_NODE_DOWN, DB_HEALTH, REDIS_HEALTH, BACKUP_FAILED, DISK_HIGH, AUTH_FAILURES
            $table->string('severity')->default('WARNING'); // INFO, WARNING, CRITICAL
            $table->string('source')->nullable(); // component or identifier
            $table->string('title');
            $table->text('message');
            $table->string('status')->default('OPEN'); // OPEN, ACKNOWLEDGED, RESOLVED
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('type');
            $table->index('created_at');
        });

        Schema::create('system_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('incident_number')->unique(); // e.g. INC-2026-0001
            $table->string('title');
            $table->string('severity')->default('SEV2'); // SEV1, SEV2, SEV3
            $table->string('status')->default('OPEN'); // OPEN, INVESTIGATING, MITIGATED, RESOLVED, POST_MORTEM
            $table->text('description');
            $table->text('impact_summary')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->foreignId('commander_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('mitigated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('action_items')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_incidents');
        Schema::dropIfExists('system_alerts');
    }
};
