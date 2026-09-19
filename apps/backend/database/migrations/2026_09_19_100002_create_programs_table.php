<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-001: academic master data — programs (database blueprint §3).
// A program belongs to a department; its code is unique WITHIN that department.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Restrict deletion of a department that still owns programs (preserve
            // academic structure; there is no destructive delete flow).
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->enum('education_level', ['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER']);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestampsTz();

            $table->unique(['department_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
