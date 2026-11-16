<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PPL-002: visits, one planned or actual visit by a visitor (database blueprint
// §15 reserved table). The access policy (PPL-008) treats a visitor as
// VISIT_ACTIVE only while a visit is valid for that site.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('visitor_person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('host_person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->text('purpose');
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->enum('status', ['SCHEDULED', 'CHECKED_IN', 'CHECKED_OUT', 'EXPIRED', 'CANCELLED'])
                ->default('SCHEDULED');
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['visitor_person_id', 'status']);
            $table->index(['site_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
