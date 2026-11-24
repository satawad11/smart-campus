<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-003 (ACR-VEH-01 Phase 5, approved 2026-11-12): gate_passes.
//
// One row per pass through a Smart Gate: the correlation service (GATE-004) links the face
// event and/or the plate event that fell inside the gate's correlation window. The pass only
// points at the events; the original access_events rows are never modified. The decision for a
// pass stays in access_decisions (GATE-005..007); this table holds no ALLOW/DENY and never
// drives a barrier (D5).
//
// Immutable once written (enforced in App\Models\GatePass). Retention removes passes together
// with their events and drops the vehicle link when plate reads are redacted (VEH-013).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Gates are never hard-deleted (deactivate instead); keep it that way for history.
            $table->foreignId('gate_id')->constrained('gates')->restrictOnDelete();
            // An event belongs to at most one pass. RESTRICT: retention deletes the pass first.
            $table->foreignId('face_event_id')->nullable()->unique()->constrained('access_events')->restrictOnDelete();
            $table->foreignId('plate_event_id')->nullable()->unique()->constrained('access_events')->restrictOnDelete();
            // Identity resolved at correlation time (snapshot, like access_events.person_id).
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->enum('match_result', ['MATCHED', 'MISMATCH', 'FACE_ONLY', 'PLATE_ONLY', 'UNKNOWN']);
            // When the pass happened: the earliest occurred_at of its events.
            $table->timestampTz('occurred_at');
            // No updated_at: an immutable record, like access_events.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['gate_id', 'occurred_at']);
            $table->index(['person_id', 'occurred_at']);
            $table->index(['vehicle_id', 'occurred_at']);
            $table->index(['match_result', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_passes');
    }
};
