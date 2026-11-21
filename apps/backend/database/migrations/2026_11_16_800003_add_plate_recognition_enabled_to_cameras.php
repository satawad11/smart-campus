<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VEH-005 (ACR-VEH-01 D1): per-camera plate-reading switch. Off for every existing camera.
// A node only reads plates when this is true AND the system flag feature.alpr is on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->boolean('plate_recognition_enabled')->default(false)->after('snapshot_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->dropColumn('plate_recognition_enabled');
        });
    }
};
