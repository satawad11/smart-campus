<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PPL-002 (Module 09, docs/12-module-09-people-visitor.md): staff_profiles.
//
// Employment profile of a teacher or staff member, linked one-to-one to a Person
// like student_profiles. employment_status is registry data, not an access
// decision (Module 05 policy maps it to ALLOW/REVIEW).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $table->string('staff_code')->unique();
            $table->string('position')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->enum('employment_status', ['ACTIVE', 'LEAVE', 'SUSPENDED', 'RESIGNED', 'RETIRED'])
                ->default('ACTIVE')
                ->index();
            $table->date('status_start_date')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
