<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PPL-002: visitor_profiles for visitors and contractors (database blueprint §15
// reserved table, unlocked by OWNER-DECISIONS-2026-11-16).
//
// PDPA minimisation: only the last 4 characters of an identity document are
// stored, never the full number.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $table->string('organization')->nullable();
            $table->string('phone', 50)->nullable();
            $table->enum('id_document_type', ['NATIONAL_ID', 'PASSPORT', 'DRIVING_LICENCE', 'OTHER'])->nullable();
            $table->string('id_document_last4', 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_profiles');
    }
};
