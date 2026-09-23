<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EVT-002: access_policies (database blueprint §7). Configuration-driven
// rules the policy matcher (EVT-006) evaluates against an AccessContext to
// produce a decision — this task only creates the schema and seeds the
// documented defaults (docs/09 "Default Policy Concept"); the matcher
// itself does not exist yet.
//
// subject_type/subject_status are plain nullable strings, not enums: only
// STUDENT + StudentAcademicStatus's value set exist today, and the spec
// explicitly locks every other subject/domain (VIS-*/DOOR-*/AIA-*,
// TASKS.md "FUTURE — LOCKED UNTIL APPROVAL") until separately approved, so
// inventing an exhaustive subject_type enum now would bake in domains that
// aren't real yet — same reasoning as Zone.zone_type / EventType.category.
//
// priority convention (documented here for EVT-006's matcher to honor):
// LOWER value is evaluated FIRST. A null site_id/zone_id/event_type/
// subject_type/subject_status means "matches regardless of that
// dimension" (a wildcard), same nullable-dimension shape access_events
// itself uses for its own optional FKs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('subject_type')->nullable();
            $table->string('subject_status')->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('event_type')->nullable();
            $table->foreign('event_type')->references('code')->on('event_types')->nullOnDelete();
            $table->enum('decision', ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN']);
            $table->string('reason_code');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestampsTz();

            $table->index(['enabled', 'priority']);
            $table->index(['subject_type', 'subject_status']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_policies');
    }
};
