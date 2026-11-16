<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PPL-002: guardian_links, a parent/guardian Person linked to a student Person.
// A guardian is usually person_type PARENT; a teacher who is also a parent keeps
// their own type and simply gets a link.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('guardian_person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('student_person_id')->constrained('persons')->restrictOnDelete();
            $table->enum('relation', ['FATHER', 'MOTHER', 'GUARDIAN', 'OTHER']);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['guardian_person_id', 'student_person_id']);
            $table->index('student_person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_links');
    }
};
