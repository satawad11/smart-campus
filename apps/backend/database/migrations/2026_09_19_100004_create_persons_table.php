<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-002: canonical Person master identity (database blueprint §3, §5, §11).
//
// person_type values are canonical (blueprint §5). persons.status is the Person
// RECORD lifecycle (ACTIVE/INACTIVE/ARCHIVED, default ACTIVE) enforced by a CHECK
// constraint — NOT student academic status.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('person_code')->nullable()->unique();
            $table->enum('person_type', [
                'STUDENT', 'TEACHER', 'STAFF', 'PARENT', 'VISITOR', 'CONTRACTOR', 'ALUMNI', 'OTHER',
            ]);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('display_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('primary_photo')->nullable(); // object-storage key, not a blob
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'ARCHIVED'])->default('ACTIVE');
            $table->timestampsTz();
            $table->softDeletesTz();

            // Index strategy (blueprint §11): persons(person_type, status).
            $table->index(['person_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
