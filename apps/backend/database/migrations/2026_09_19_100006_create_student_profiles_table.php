<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-003: student_profiles (database blueprint §3, §11).
//
// Academic profile linked to a Person (person_id UNIQUE → canonical 0..1:1).
// academic_status is registry data enforced by a CHECK constraint; it is NOT an
// access decision (Module 05 policy owns ALLOW/REVIEW/DENY). No soft delete
// (blueprint lists no deleted_at); archive lifecycle is a later task (STD-009).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Exactly one Person per profile; RESTRICT so a hard person delete
            // cannot destroy the student record (person_id is required, so SET
            // NULL is not applicable).
            $table->foreignId('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $table->string('student_code')->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->enum('education_level', ['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER']);
            $table->unsignedSmallInteger('year_level');
            $table->enum('academic_status', ['ACTIVE', 'LEAVE', 'SUSPENDED', 'WITHDRAWN', 'GRADUATED', 'EXPELLED', 'UNKNOWN'])
                ->default('UNKNOWN')
                ->index();
            $table->date('status_start_date')->nullable();
            $table->date('status_end_date')->nullable();
            $table->unsignedSmallInteger('admission_year')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            // Index strategy (blueprint §11): department and classroom lookups.
            $table->index('department_id');
            $table->index('classroom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
