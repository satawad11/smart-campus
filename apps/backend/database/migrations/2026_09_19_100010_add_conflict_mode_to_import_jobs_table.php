<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-014: Add conflict_mode to import_jobs table.
//
// Tracks the duplicate/conflict resolution mode for the import job:
// CREATE_ONLY (default), SKIP_EXISTING, or UPDATE_EXISTING.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->string('conflict_mode', 50)->default('CREATE_ONLY')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('conflict_mode');
        });
    }
};
