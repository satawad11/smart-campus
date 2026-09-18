<?php

namespace Database\Seeders;

use App\Enums\RoleStatus;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed canonical initial system roles.
     *
     * Idempotent: uses updateOrCreate on role code.
     * Preserves existing role UUIDs across repeated runs.
     */
    public function run(): void
    {
        $roles = $this->getInitialRoles();

        foreach ($roles as $roleData) {
            Role::updateOrCreate(
                ['code' => $roleData['code']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'] ?? null,
                    'system_role' => $roleData['system_role'],
                    'status' => $roleData['status'],
                ]
            );
        }
    }

    /**
     * Returns the 5 initial canonical system roles.
     *
     * @return list<array{code: string, name: string, description: string, system_role: bool, status: RoleStatus}>
     */
    public function getInitialRoles(): array
    {
        return [
            [
                'code' => 'SUPER_ADMIN',
                'name' => 'Super Administrator',
                'description' => 'Super Administrator with full system and security access',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            [
                'code' => 'SYSTEM_ADMIN',
                'name' => 'System Administrator',
                'description' => 'System Administrator managing technical platform configuration and operations',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            [
                'code' => 'DISCIPLINE_OFFICER',
                'name' => 'เจ้าหน้าที่งานปกครอง',
                'description' => 'Discipline officer managing student disciplinary records and incident reviews',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            [
                'code' => 'SECURITY_OFFICER',
                'name' => 'เจ้าหน้าที่รักษาความปลอดภัย',
                'description' => 'Security officer monitoring live campus cameras and access events',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            [
                'code' => 'EXECUTIVE_VIEWER',
                'name' => 'ผู้บริหาร',
                'description' => 'Executive viewer with access to aggregate dashboards and executive reports',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
        ];
    }
}
