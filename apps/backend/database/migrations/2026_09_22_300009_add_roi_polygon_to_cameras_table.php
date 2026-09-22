<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-016: adds the camera's optional region-of-interest polygon.
// Non-destructive addition on top of the CAM-001 schema (same pattern as
// CAM-003's ai_nodes software_version/capabilities columns). Points are
// normalized (0.0-1.0, relative to frame width/height) so a drawn ROI
// stays correct if the stream's actual resolution later changes. Null
// means "no ROI configured" — process the whole frame, the pre-CAM-016
// default behavior.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->jsonb('roi_polygon')->nullable()->after('snapshot_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->dropColumn('roi_polygon');
        });
    }
};
