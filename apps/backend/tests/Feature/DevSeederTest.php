<?php

namespace Tests\Feature;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seed_runs_without_error_on_clean_db(): void
    {
        $this->artisan('db:seed')->assertExitCode(0);
    }

    public function test_demo_seeder_is_development_only(): void
    {
        $this->assertTrue(DemoDataSeeder::shouldRun('local'));
        $this->assertTrue(DemoDataSeeder::shouldRun('testing'));
        $this->assertFalse(DemoDataSeeder::shouldRun('production'));
    }
}
