<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EVT-001: access_events (database blueprint §7). The durable record of a
// recognition occurrence — perception only, never an access decision (see
// access_decisions, a separate table: "Event != Decision", docs/09). Event
// and Decision are deliberately two tables so a decision can later be
// appended (manual review) without ever rewriting the original event.
//
// event_type stores the event_types.code string directly (not a numeric
// id) — self-describing in raw rows, FK'd against event_types.code for
// referential integrity. FK delete behavior mirrors CAM-001's Camera
// precedent: the one non-nullable anchor (site_id) restricts delete;
// every optional/nullable dimension (person/zone/camera/ai_node) nulls out
// instead of blocking or cascading — the database blueprint is explicit
// that historical events must never be cascade-deleted.
//
// No updated_at: this is an immutable telemetry/history record, same
// convention as CameraHealthLog/AiNodeHealthLog.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('camera_id')->nullable()->constrained('cameras')->nullOnDelete();
            $table->foreignId('ai_node_id')->nullable()->constrained('ai_nodes')->nullOnDelete();
            $table->string('event_type');
            $table->foreign('event_type')->references('code')->on('event_types')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->nullable();
            $table->float('face_score')->nullable();
            $table->float('liveness_score')->nullable();
            $table->float('quality_score')->nullable();
            $table->string('track_id')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('occurred_at');
            $table->index(['person_id', 'occurred_at']);
            $table->index(['camera_id', 'occurred_at']);
            $table->index(['site_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
