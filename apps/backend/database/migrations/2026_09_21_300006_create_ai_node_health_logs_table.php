<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-001: ai_node_health_logs (database blueprint §6). Historical
// operational telemetry only — no secrets, no biometric data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_node_id')->constrained('ai_nodes')->restrictOnDelete();
            $table->float('cpu_usage')->nullable();
            $table->float('ram_usage')->nullable();
            $table->float('gpu_usage')->nullable();
            $table->float('gpu_memory_usage')->nullable();
            $table->float('temperature')->nullable();
            $table->unsignedSmallInteger('active_camera_count')->nullable();
            $table->unsignedInteger('queue_depth')->nullable();
            $table->enum('status', ['ONLINE', 'DEGRADED', 'OFFLINE'])->index();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->index(['ai_node_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_node_health_logs');
    }
};
