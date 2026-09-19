<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-011: import_errors table (database blueprint §9).
//
// Records row-level validation and execution errors during import.
// Cascade-deleted when the parent import job is deleted.
// Immutable historical record (created_at only, no updated_at).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('field', 100)->nullable();
            $table->text('value')->nullable();
            $table->string('error_code', 100);
            $table->text('error_message');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['import_job_id', 'row_number']);
            $table->index('error_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
    }
};
