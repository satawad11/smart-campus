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
     * FND-012: development-only demo strategy. Concrete module seeders
     * (AUTH-003 RBAC, CAM-* site/zones, EVT-* demo events) register themselves
     * here as they are implemented. For now only the documented placeholder runs.
     */
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);
    }
}
