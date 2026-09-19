<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-002: canonical Person master identity (database blueprint §3, §5, §11).
//
// person_type values are canonical (blueprint §5). persons.status has NO
// enumerated value set in the canonical docs, so it is stored as a plain string
// WITHOUT a CHECK constraint (minimum reversible choice, PROJECT_RULES §18) —
// a value policy can be added later additively. See the implementation report's
// SCHEMA CLARIFICATION section. Academic status (student) is NOT used here.
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
            $table->string('status'); // value set deferred (no CHECK) — see report
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
