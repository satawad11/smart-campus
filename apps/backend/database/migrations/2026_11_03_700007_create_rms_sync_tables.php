<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ACR-INT-01: RMS API Center sync. rms_links maps RMS ids (student code,
// s_... staff id, group id) to platform records; rms_sync_runs keeps the
// report of every run (counts and review items, no names). Adds the
// student.sync permission and grants it to the two administrator roles on
// installations whose role matrix was already seeded (the seeder never
// appends to an initialised matrix).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rms_links', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 20); // STUDENT | STAFF | GROUP
            $table->string('external_id', 100);
            $table->string('entity_type', 30); // student_profile | person | classroom
            $table->unsignedBigInteger('entity_id');
            $table->string('payload_hash', 64);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('missing_since')->nullable();
            $table->timestampsTz();

            $table->unique(['resource', 'external_id']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('rms_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 20)->index(); // RUNNING | COMPLETED | FAILED
            $table->string('trigger', 20); // SCHEDULE | MANUAL | CLI
            $table->boolean('dry_run')->default(false);
            $table->jsonb('counts')->nullable();
            $table->jsonb('issues')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        $permissionId = DB::table('permissions')->where('code', 'student.sync')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'code' => 'student.sync',
                'module' => 'student',
                'name' => 'Sync Students from RMS',
                'description' => 'Run and review the RMS student and staff sync',
            ]);

        if (DB::table('role_permissions')->exists()) {
            foreach (DB::table('roles')->whereIn('code', ['SUPER_ADMIN', 'SYSTEM_ADMIN'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rms_sync_runs');
        Schema::dropIfExists('rms_links');
    }
};
