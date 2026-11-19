<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-005 (ACR-VEH-01 Phase 5 item 8): Smart Gate dimensions on access_policies, so the existing
// policy matcher can decide a gate pass. Same shape as the other dimensions: null is a wildcard,
// a value must equal the AccessContext's value exactly. A context without a gate (an ordinary face
// event) carries null for all four, so it can never match a policy that sets any of them.
//
// gate_id is restrict-delete, not null-on-delete: losing the gate must never widen a gate-specific
// policy into one that applies to every gate. Gates are soft-deleted, so this only blocks a hard
// delete of a gate a policy still names.
//
// Plain strings, like subject_type: the values are checked by the model's enum casts
// (GateMode, GateMatchResult, VehicleStatus).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_policies', function (Blueprint $table) {
            $table->foreignId('gate_id')->nullable()->after('event_type')->constrained('gates')->restrictOnDelete();
            $table->string('gate_mode')->nullable()->after('gate_id');
            $table->string('match_result')->nullable()->after('gate_mode');
            $table->string('vehicle_status')->nullable()->after('match_result');

            $table->index(['gate_mode', 'match_result']);
        });
    }

    public function down(): void
    {
        Schema::table('access_policies', function (Blueprint $table) {
            $table->dropIndex(['gate_mode', 'match_result']);
            $table->dropConstrainedForeignId('gate_id');
            $table->dropColumn(['gate_mode', 'match_result', 'vehicle_status']);
        });
    }
};
