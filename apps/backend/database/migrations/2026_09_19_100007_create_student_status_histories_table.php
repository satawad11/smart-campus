<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-004: student_status_histories (database blueprint §3).
//
// Append-only record of academic-status transitions. old_status/new_status use
// StudentAcademicStatus (NOT PersonStatus, NOT an access decision). effective_*
// are business-time (when the status applied); created_at is system-time (when
// the row was recorded). No updated_at — history rows are immutable. The schema
// backfills no initial history; the status-change service (later task) owns
// writing rows in a transaction alongside student_profiles.academic_status.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_status_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Preserve academic history: RESTRICT so a student_profile with
            // history cannot be hard-deleted out from under it.
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->enum('old_status', ['ACTIVE', 'LEAVE', 'SUSPENDED', 'WITHDRAWN', 'GRADUATED', 'EXPELLED', 'UNKNOWN'])->nullable();
            $table->enum('new_status', ['ACTIVE', 'LEAVE', 'SUSPENDED', 'WITHDRAWN', 'GRADUATED', 'EXPELLED', 'UNKNOWN']);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->enum('source', ['MANUAL', 'IMPORT', 'API', 'SIS_SYNC']);
            // Preserve history if the acting user is later removed.
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            // Deterministic per-student chronological retrieval.
            $table->index(['student_profile_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_histories');
    }
};
