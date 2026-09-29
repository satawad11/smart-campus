<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RPT-018: scheduled_reports (docs/01-database-blueprint.md §10,
 * docs/10-module-06-reporting.md "Reports").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('report_type');
            $table->string('schedule_type');
            $table->jsonb('schedule_config')->nullable();
            $table->jsonb('filters')->nullable();
            $table->string('format');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampsTz();

            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
