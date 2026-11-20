<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// AUDIT-A14 (owner decision 2026-11-20): SECURITY_OFFICER holds dashboard.view so
// the landing page (/dashboard, which accepts dashboard.view or report.view since
// 144164d) opens for security officers. Fresh installs get the grant from
// RolePermissionSeeder; the seeder never appends to an initialised matrix, so this
// ensures it on existing installs. Adds only this one grant, idempotently, and
// touches no other role or permission.
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('role_permissions')->exists()) {
            return; // Not seeded yet: RolePermissionSeeder grants it.
        }

        $roleId = DB::table('roles')->where('code', 'SECURITY_OFFICER')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'dashboard.view')->value('id');

        if ($roleId === null || $permissionId === null) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    public function down(): void
    {
        // No-op: the grant has been part of the seeded matrix since AUTH-004, so
        // rolling this migration back must not remove it from installs that had it.
    }
};
