<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RPT-003: hourly pre-aggregated event counts (docs/10-module-06-reporting.md
// "Summary Layer" — "Use hourly/daily summaries or materialized structures
// for performance. Raw events remain source of truth"). This table is a
// derived cache, never a source of truth: it holds no data AccessEvent/
// AccessDecision don't already have, and every row here must be
// reproducible by re-aggregating raw events for that hour (RPT-004's
// refresh/backfill job does exactly that, idempotently, via the unique key
// below).
//
// One row per (site, zone, camera, department, hour, event_type, decision)
// combination — dimensions are nullable, where NULL means "not broken down
// by this dimension" (e.g. zone_id IS NULL alongside a non-null site_id is
// a site-wide total row, distinct from any specific zone's row), covering
// the dashboard's "by hour / by decision / by camera / by department"
// charts from one table rather than four separate ones.
//
// event_count vs unique_person_count are DELIBERATELY separate columns
// (docs/10 "Accuracy Rules": "distinguish total events vs unique persons")
// — the same person recognized 5 times in one hour is 5 events but 1
// unique person, and an UNKNOWN_PERSON event contributes to event_count
// but never to unique_person_count (no person_id to be unique by).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_hourly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->cascadeOnDelete();
            $table->foreignId('camera_id')->nullable()->constrained('cameras')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->timestampTz('hour_bucket'); // truncated to the top of the hour, UTC
            $table->string('event_type');
            $table->enum('decision', ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN']);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('unique_person_count')->default(0);
            $table->timestampTz('refreshed_at')->useCurrent();

            // Best-effort DB-level safety net, not a complete guarantee: SQL
            // treats each NULL as distinct for uniqueness purposes, so this
            // constraint only fully prevents duplicates when every nullable
            // dimension is non-null for a given row. RPT-004's refresh job
            // is the actual source of correctness — it must match existing
            // rows with explicit whereNull()/where() per dimension (never a
            // blind insert) before deciding whether to update or create.
            $table->unique(
                ['site_id', 'zone_id', 'camera_id', 'department_id', 'hour_bucket', 'event_type', 'decision'],
                'event_hourly_summaries_dimensions_unique'
            );
            $table->index('hour_bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_hourly_summaries');
    }
};
