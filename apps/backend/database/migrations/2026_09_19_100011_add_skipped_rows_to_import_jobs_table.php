<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-014: Add skipped_rows counter to import_jobs table.
//
// Tracks skipped rows during import execution (e.g. SKIP_EXISTING policy on matching identity).
// Invariant after execution: valid_rows = success_rows + failed_rows + skipped_rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->unsignedInteger('skipped_rows')->default(0)->after('failed_rows');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('skipped_rows');
        });
    }
};
