<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialAdminUserSeeder extends Seeder
{
    /**
     * Seeds initial standard test/demo accounts across all 5 canonical roles.
     * Idempotent: checks for existing usernames before creating.
     */
    public function run(): void
    {
        $accounts = [
            [
                'username' => 'superadmin',
                'name' => 'Super Administrator',
                'email' => 'superadmin@campus.local',
                'password' => 'Password123!',
                'role' => 'SUPER_ADMIN',
            ],
            [
                'username' => 'sysadmin',
                'name' => 'System Administrator',
                'email' => 'sysadmin@campus.local',
                'password' => 'Password123!',
                'role' => 'SYSTEM_ADMIN',
            ],
            [
                'username' => 'discipline',
                'name' => 'Discipline Officer',
                'email' => 'discipline@campus.local',
                'password' => 'Password123!',
                'role' => 'DISCIPLINE_OFFICER',
            ],
            [
                'username' => 'security',
                'name' => 'Security Officer',
                'email' => 'security@campus.local',
                'password' => 'Password123!',
                'role' => 'SECURITY_OFFICER',
            ],
            [
                'username' => 'executive',
                'name' => 'Executive Viewer',
                'email' => 'executive@campus.local',
                'password' => 'Password123!',
                'role' => 'EXECUTIVE_VIEWER',
            ],
        ];

        foreach ($accounts as $acc) {
            $user = User::where('username', $acc['username'])->first();
            if (! $user) {
                $user = User::create([
                    'username' => $acc['username'],
                    'email' => $acc['email'],
                    'password' => Hash::make($acc['password']),
                    'status' => UserStatus::ACTIVE,
                    'mfa_enabled' => false,
                    'must_change_password' => false,
                ]);
            }

            $role = Role::where('code', $acc['role'])->first();
            if ($role && ! UserRole::where('user_id', $user->id)->where('role_id', $role->id)->exists()) {
                UserRole::create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ]);
            }
        }
    }
}
