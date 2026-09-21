<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FACE-002: face_profiles table (database blueprint §5, Module 03).
//
// Represents the biometric enrollment identity and lifecycle of a Person.
//
// Invariants:
// - Biometric identity belongs to Person (person_id), NOT StudentProfile or User.
// - Canonical 0..1:1 cardinality (person_id is UNIQUE).
// - Deleting Person is RESTRICTED while biometric history exists.
// - Public identification by UUID only.
// - Zero vector embedding columns in this task (pgvector search is FACE-008).
// - Zero access decisions or door actuator properties.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $table->foreignId('recognition_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->unsignedInteger('embedding_dimension')->nullable();
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->enum('status', ['ACTIVE', 'PENDING', 'REVOKED', 'RE_ENROLL_REQUIRED'])->default('PENDING');
            $table->foreignId('enrolled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('enrolled_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();
            $table->timestampsTz();

            $table->index('status');
            $table->index('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_profiles');
    }
};
