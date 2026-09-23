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
     * Module 05 (EVT-002): Canonical event types, then the default access policies that reference
     * them by code (event_types must exist first — access_policies.event_type is FK'd to it).
     * Production-safe; idempotent across multiple invocations.
     * FND-012: Development-only demo data runs after reference data.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            EventTypeSeeder::class,
            AccessPolicySeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
