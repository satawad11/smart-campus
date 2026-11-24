<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-007 (ACR-VEH-01 Phase 5 item 8): "Decisions go into access_decisions as today". A Smart Gate
// decision is an ordinary AccessDecision on the pass's face event (its plate event for a PLATE_ONLY
// pass), and gate_pass_id says which pass it decided. An officer review of that event carries the
// same gate_pass_id forward. Null for every non-gate decision.
//
// Null on delete: retention removes a pass together with its events (and their decisions); if a
// pass ever goes while a decision on a surviving event still points at it, the decision stays and
// only loses the link.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_decisions', function (Blueprint $table) {
            $table->foreignId('gate_pass_id')->nullable()->after('access_policy_id')->constrained('gate_passes')->nullOnDelete();
            $table->index(['gate_pass_id', 'decision_source']);
        });
    }

    public function down(): void
    {
        Schema::table('access_decisions', function (Blueprint $table) {
            $table->dropIndex(['gate_pass_id', 'decision_source']);
            $table->dropConstrainedForeignId('gate_pass_id');
        });
    }
};
