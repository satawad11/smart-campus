<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// GATE-002 (ACR-VEH-01 Phase 5): Smart Gate permissions for installations whose role matrix was
// already seeded (same pattern as VEH-004). Owner decision in chat, 2026-11-17: new
// gate.view / gate.manage; SUPER_ADMIN / SYSTEM_ADMIN manage, SECURITY_OFFICER views.
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> code => [module, name, description] */
    private const PERMISSIONS = [
        'gate.view' => ['gate', 'View Gates', 'View Smart Gates and the cameras assigned to them'],
        'gate.manage' => ['gate', 'Manage Gates', 'Create Smart Gates, change their settings and assign cameras'],
    ];

    private const ROLE_GRANTS = [
        'SECURITY_OFFICER' => ['gate.view'],
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
