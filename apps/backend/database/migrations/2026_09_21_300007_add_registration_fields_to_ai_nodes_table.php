<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-003: adds self-reported registration/heartbeat metadata to ai_nodes.
// Non-destructive addition on top of the CAM-001 schema (same pattern as
// STD-012 extending import_jobs after STD-011 shipped). Both columns are
// backend-observed facts reported BY the node, never authorization/scope —
// site assignment stays admin-owned and untouched by these fields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->string('software_version')->nullable()->after('hardware_type');
            $table->jsonb('capabilities')->nullable()->after('software_version');
        });
    }

    public function down(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->dropColumn(['software_version', 'capabilities']);
        });
    }
};
