<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EVT-001: access_decisions (database blueprint §7). One decision belongs
// to exactly one event, but an event may accumulate several decisions over
// time — the original system/policy decision, and later independent
// officer manual-review decisions — since a decision is an immutable,
// appended record (docs/09: "Officer review adds a new decision. It never
// overwrites system decision"). No updated_at, same append-only convention
// as CameraHealthLog/AiNodeHealthLog: a row is corrected by adding another
// row, never by editing this one.
//
// access_policy_id (nullable FK to access_policies) is deliberately NOT
// included here — access_policies doesn't exist until EVT-002 creates it;
// that task adds the column via its own migration, the same
// "add_X_to_Y_table" pattern already used elsewhere in this schema
// (e.g. CAM-016's roi_polygon).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained('access_events')->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->enum('decision', ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN']);
            $table->string('reason_code')->nullable();
            $table->enum('decision_source', ['SYSTEM', 'POLICY', 'OFFICER']);
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason_text')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['event_id', 'created_at']);
            $table->index(['person_id', 'created_at']);
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_decisions');
    }
};
