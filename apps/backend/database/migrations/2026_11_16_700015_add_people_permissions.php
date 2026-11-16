<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// PPL-002: Module 09 permissions (staff, guardians, visitors, visits) for
// installations whose role matrix was already seeded. The seeder never appends to
// an initialised matrix, so existing installs get the grants here; fresh installs
// get them from PermissionSeeder + RolePermissionSeeder.
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> code => [module, name, description] */
    private const PERMISSIONS = [
        'staff.view' => ['staff', 'View Staff', 'View teacher and staff profiles'],
        'staff.create' => ['staff', 'Create Staff', 'Create teacher and staff profiles'],
        'staff.update' => ['staff', 'Update Staff', 'Update teacher and staff profile details'],
        'staff.status.change' => ['staff', 'Change Staff Status', 'Change the employment status of a teacher or staff member'],
        'staff.import' => ['staff', 'Import Staff', 'Batch import teachers and staff from a file'],
        'guardian.view' => ['guardian', 'View Guardians', 'View parents/guardians and their student links'],
        'guardian.manage' => ['guardian', 'Manage Guardians', 'Create guardians and link them to students'],
        'visitor.view' => ['visitor', 'View Visitors', 'View visitor and contractor profiles'],
        'visitor.manage' => ['visitor', 'Manage Visitors', 'Create and update visitor and contractor profiles'],
        'visit.view' => ['visitor', 'View Visits', 'View planned and current visits'],
        'visit.manage' => ['visitor', 'Manage Visits', 'Create visits and check visitors in and out'],
    ];

    /** Role grants on an already-initialised matrix (SUPER_ADMIN and SYSTEM_ADMIN get everything). */
    private const ROLE_GRANTS = [
        'SECURITY_OFFICER' => ['staff.view', 'guardian.view', 'visitor.view', 'visitor.manage', 'visit.view', 'visit.manage'],
        'DISCIPLINE_OFFICER' => ['staff.view', 'guardian.view', 'visit.view'],
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
