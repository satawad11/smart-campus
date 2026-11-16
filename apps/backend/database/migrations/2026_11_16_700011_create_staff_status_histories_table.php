<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PPL-002: staff_status_histories, the staff counterpart of
// student_status_histories (employment status workflow history).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_status_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->enum('old_status', ['ACTIVE', 'LEAVE', 'SUSPENDED', 'RESIGNED', 'RETIRED'])->nullable();
            $table->enum('new_status', ['ACTIVE', 'LEAVE', 'SUSPENDED', 'RESIGNED', 'RETIRED']);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->enum('source', ['MANUAL', 'IMPORT', 'API', 'SIS_SYNC']);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['staff_profile_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_status_histories');
    }
};
