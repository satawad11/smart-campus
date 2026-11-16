<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// VEH-004 (ACR-VEH-01): vehicle registry permissions for installations whose role matrix
// was already seeded. The seeder never appends to an initialised matrix, so existing installs
// get the grants here; fresh installs get them from PermissionSeeder + RolePermissionSeeder.
// Decision D6: SUPER_ADMIN / SYSTEM_ADMIN manage, SECURITY_OFFICER views.
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> code => [module, name, description] */
    private const PERMISSIONS = [
        'vehicle.view' => ['vehicle', 'View Vehicles', 'View registered vehicles and who they are linked to'],
        'vehicle.manage' => ['vehicle', 'Manage Vehicles', 'Register vehicles, change their status and link or unlink people'],
    ];

    private const ROLE_GRANTS = [
        'SECURITY_OFFICER' => ['vehicle.view'],
    ];

    public function up(): void
    {
        $ids = [];
        foreach (self::PERMISSIONS as $code => [$module, $name, $description]) {
            $ids[$code] = DB::table('permissions')->where('code', $code)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'code' => $code,
                    'module' => $module,
                    'name' => $name,
                    'description' => $description,
                ]);
        }

        if (! DB::table('role_permissions')->exists()) {
            return;
        }

        $grants = self::ROLE_GRANTS + [
            'SUPER_ADMIN' => array_keys(self::PERMISSIONS),
            'SYSTEM_ADMIN' => array_keys(self::PERMISSIONS),
        ];

        foreach ($grants as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($codes as $code) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$code]]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
