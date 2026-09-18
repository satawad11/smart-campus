<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed canonical role-permission assignment matrix.
     *
     * Idempotent: uses Eloquent BelongsToMany sync() to align role permissions.
     * Does NOT touch user_roles or user_permissions tables.
     */
    public function run(): void
    {
        $matrix = $this->getRolePermissionMatrix();

        foreach ($matrix as $roleCode => $permissionCodes) {
            $role = Role::where('code', $roleCode)->first();

            if (! $role) {
                continue;
            }

            if ($permissionCodes === ['*']) {
                $permissionIds = Permission::pluck('id')->all();
            } else {
                $permissionIds = Permission::whereIn('code', $permissionCodes)->pluck('id')->all();
            }

            $role->permissions()->sync($permissionIds);
        }
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
