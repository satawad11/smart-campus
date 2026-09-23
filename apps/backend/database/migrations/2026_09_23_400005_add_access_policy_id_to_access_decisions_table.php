<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EVT-002: adds the access_policy_id column deferred from EVT-001 (that
// task's access_decisions migration ran before access_policies existed).
// Nullable: a decision may have no matching policy at all (e.g.
// decision_source=SYSTEM for a recognition-classified-UNKNOWN event, or
// decision_source=OFFICER for a manual review — neither is policy-driven).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_decisions', function (Blueprint $table) {
            $table->foreignId('access_policy_id')
                ->nullable()
                ->after('reason_code')
                ->constrained('access_policies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_policy_id');
        });
    }
};
