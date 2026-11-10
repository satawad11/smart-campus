<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Permissions that a wildcard ('*') grants to SUPER_ADMIN only.
     * system.update starts a host-level update (ACR-OPS-01).
     *
     * @var list<string>
     */
    public const SUPER_ADMIN_ONLY = ['system.update'];

    /**
     * Seed canonical role-permission assignment matrix.
     *
     * Non-destructive & Bootstrap-safe:
     * - Seeds canonical AUTH-003 baseline matrices ONLY when role_permissions has not yet been initialized.
     * - If role_permissions is already initialized, runtime edits from administrators are authoritative
     *   and ordinary db:seed will NOT overwrite, sync, restore, or append role permissions.
     * - Does NOT touch user_roles or user_permissions tables.
     */
    public function run(): void
    {
        if (DB::table('role_permissions')->exists()) {
            return;
        }

        DB::transaction(function () {
            if (DB::table('role_permissions')->exists()) {
                return;
            }

            $matrix = $this->getRolePermissionMatrix();

            foreach ($matrix as $roleCode => $permissionCodes) {
                $role = Role::where('code', $roleCode)->first();

                if (! $role) {
                    continue;
                }

                if ($permissionCodes === ['*']) {
                    $permissionIds = Permission::query()
                        ->when($roleCode !== 'SUPER_ADMIN', fn ($q) => $q->whereNotIn('code', self::SUPER_ADMIN_ONLY))
                        ->pluck('id')->all();
                } else {
                    $permissionIds = Permission::whereIn('code', $permissionCodes)->pluck('id')->all();
                }

                $role->permissions()->attach($permissionIds);
            }
        });
    }

    /**
     * Returns the canonical initial role-to-permission mapping.
     *
     * @return array<string, list<string>>
     */
    public function getRolePermissionMatrix(): array
    {
        return [
            // SUPER_ADMIN: Receives all approved Production V1 permissions.
            'SUPER_ADMIN' => ['*'],

            // SYSTEM_ADMIN: Broad technical and operational administration permissions.
            'SYSTEM_ADMIN' => ['*'],

            // DISCIPLINE_OFFICER: Student discipline, monitoring, face enrollment, and review.
            'DISCIPLINE_OFFICER' => [
                'dashboard.view',
                'monitor.view',
                'student.view',
                'student.update',
                'student.status.change',
                'face.view',
                'face.enroll',
                'event.view',
                'event.review',
                'event.note',
                'report.view',
                'report.export',
                'academic.view',
            ],

            // SECURITY_OFFICER: Live security monitoring, camera viewing, and event viewing.
            'SECURITY_OFFICER' => [
                'dashboard.view',
                'monitor.view',
                'student.view',
                'camera.view',
                'camera.live',
                'event.view',
            ],

            // EXECUTIVE_VIEWER: Read-only aggregated metrics and executive reports.
            'EXECUTIVE_VIEWER' => [
                'dashboard.view',
                'report.view',
                'report.executive',
            ],
        ];
    }
}
