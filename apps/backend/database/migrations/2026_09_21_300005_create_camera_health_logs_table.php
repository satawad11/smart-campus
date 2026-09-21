<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-001: camera_health_logs (database blueprint §6). Historical
// operational telemetry only — no secrets, no frames/images. restrictOnDelete
// mirrors the repository's established history-preservation pattern (e.g.
// student_status_histories -> student_profiles); in practice a camera is
// soft-deleted, not hard-deleted, so this restriction is never expected to
// trigger under normal application flows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camera_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_id')->constrained('cameras')->restrictOnDelete();
            $table->enum('status', ['ONLINE', 'DEGRADED', 'OFFLINE'])->index();
            $table->float('fps')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('dropped_frames')->nullable();
            $table->string('resolution')->nullable();
            $table->float('brightness_score')->nullable();
            $table->float('blur_score')->nullable();
            $table->unsignedInteger('reconnect_count')->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->index(['camera_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_health_logs');
    }
};
