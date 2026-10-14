<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDPA G-1: record when a revoked person's biometric data was erased.
 * Additive only. Rows are kept (history and audit references stay valid);
 * the embedding is nulled and the sample image objects are deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_profiles', function (Blueprint $table) {
            $table->timestampTz('biometrics_purged_at')->nullable();
        });

        Schema::table('face_samples', function (Blueprint $table) {
            $table->timestampTz('purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('face_samples', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });

        Schema::table('face_profiles', function (Blueprint $table) {
            $table->dropColumn('biometrics_purged_at');
        });
    }
};
