<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Development-only demo data seeder (FND-012).
 *
 * This is a DOCUMENTED PLACEHOLDER. The concrete demo dataset cannot be created
 * yet because the tables/models it needs are introduced by later modules
 * (PROJECT_RULES.md — do not implement later modules early):
 *
 *   - Site + Zones .......... Module 04 (CAM-*)
 *   - Roles/Permissions/Users Module 01 (AUTH-003 idempotent RBAC seeders)
 *   - Demo recognition events Module 05 (EVT-*), driven by AI_MODE=mock
 *
 * Each of those modules adds its own seeder and calls it from DatabaseSeeder.
 * This placeholder performs no writes so `php artisan db:seed` succeeds on a
 * clean database today, and it never runs in production.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! self::shouldRun(app()->environment())) {
            $this->command?->warn('DemoDataSeeder skipped: development-only (current env is production).');

            return;
        }

        $this->command?->info('DemoDataSeeder: placeholder — demo tables are added by later modules.');
    }

    /**
     * Demo/seed data is for development environments only, never production.
     */
    public static function shouldRun(string $environment): bool
    {
        return $environment !== 'production';
    }
}
