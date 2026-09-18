<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Module 01 (AUTH-003): Canonical RBAC reference data (Permissions, Roles, Role-Permission matrix).
     * Production-safe; idempotent across multiple invocations.
     * FND-012: Development-only demo data runs after reference data.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
