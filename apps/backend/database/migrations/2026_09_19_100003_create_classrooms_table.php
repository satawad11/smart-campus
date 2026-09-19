<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-001: academic master data — classrooms (database blueprint §3).
// A classroom belongs to a department (and optionally a program) for a given
// academic year; its code is unique within (department, academic_year).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            // Optional program link; if a program is removed the classroom remains.
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('academic_year')->index();
            $table->unsignedSmallInteger('year_level');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestampsTz();

            $table->unique(['department_id', 'academic_year', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
