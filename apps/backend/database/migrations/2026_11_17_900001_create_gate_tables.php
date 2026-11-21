<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// GATE-001 (ACR-VEH-01 Phase 5, approved 2026-11-12): gates and gate_cameras.
//
// A gate is an entry/exit point of a zone, watched by one or more cameras: a face camera, a
// plate camera, or one camera doing both (decision D3). Configuration only: nothing reads it
// until the correlation service (GATE-004). A gate never drives a barrier; decision and
// notification only (D5). Actuation needs Phase 2 and its own ACR.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Preserve location structure: a site or zone with gates cannot be hard-deleted.
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->enum('direction', ['IN', 'OUT', 'BOTH'])->default('BOTH');
            // Face and plate events of one pass must fall within this many seconds (GATE-004).
            $table->unsignedSmallInteger('correlation_window_seconds')->default(10);
            // Decision mode (D2). FACE_PRIMARY is the only approved mode: a plate alone never
            // produces ALLOW.
            $table->enum('mode', ['FACE_PRIMARY'])->default('FACE_PRIMARY');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('zone_id');
        });

        // One live gate per code within a site; a soft-deleted gate frees its code.
        DB::statement('CREATE UNIQUE INDEX gates_site_code_unique_live ON gates (site_id, code) WHERE deleted_at IS NULL');

        Schema::create('gate_cameras', function (Blueprint $table) {
            $table->id();
            // Configuration link: removing a gate removes its camera assignments.
            $table->foreignId('gate_id')->constrained('gates')->cascadeOnDelete();
            // A camera serves at most one gate, so an event's camera resolves to one gate.
            $table->foreignId('camera_id')->unique()->constrained('cameras')->restrictOnDelete();
            $table->enum('role', ['FACE', 'PLATE', 'BOTH']);
            $table->timestampsTz();

            $table->index('gate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_cameras');
        DB::statement('DROP INDEX IF EXISTS gates_site_code_unique_live');
        Schema::dropIfExists('gates');
    }
};
