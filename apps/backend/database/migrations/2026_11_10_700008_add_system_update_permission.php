<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ACR-OPS-01: system.update permission (web System update page), granted to
// SUPER_ADMIN only on installations whose role matrix was already seeded
// (the seeder never appends to an initialised matrix). Fresh installs get it
// from PermissionSeeder + RolePermissionSeeder.
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'system.update')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'code' => 'system.update',
                'module' => 'system',
                'name' => 'Update System Version',
                'description' => 'View available releases and start a system update from the web app',
            ]);

        if (DB::table('role_permissions')->exists()) {
            $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'system.update')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('user_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
