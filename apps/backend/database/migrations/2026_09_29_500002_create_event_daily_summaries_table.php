<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RPT-003: daily pre-aggregated event counts — same shape and purpose as
// event_hourly_summaries (see that migration's comment for the full
// rationale: derived cache, never source of truth, event_count vs
// unique_person_count kept separate per docs/10's Accuracy Rules), just at
// day granularity for the daily/weekly/monthly/yearly report tasks
// (RPT-007..RPT-010), which would otherwise need to SUM() hundreds of
// hourly rows per report instead of one row per day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->cascadeOnDelete();
            $table->foreignId('camera_id')->nullable()->constrained('cameras')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->date('summary_date'); // the calendar day, UTC
            $table->string('event_type');
            $table->enum('decision', ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN']);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('unique_person_count')->default(0);
            $table->timestampTz('refreshed_at')->useCurrent();

            // Same best-effort caveat as event_hourly_summaries: NULL
            // dimensions are not fully deduplicated by this constraint
            // alone — RPT-004's refresh job is the real source of
            // correctness (explicit whereNull()/where() matching).
            $table->unique(
                ['site_id', 'zone_id', 'camera_id', 'department_id', 'summary_date', 'event_type', 'decision'],
                'event_daily_summaries_dimensions_unique'
            );
            $table->index('summary_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_daily_summaries');
    }
};
