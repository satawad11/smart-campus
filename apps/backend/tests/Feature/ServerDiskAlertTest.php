<?php

namespace Tests\Feature;

use App\Models\SystemAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COM-05: system:monitor alerts once when the server storage volume runs low.
 */
class ServerDiskAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_free_space_raises_one_critical_alert(): void
    {
        config(['backup.disk_min_free_percent' => 100.0]); // any real disk is below 100% free

        $this->artisan('system:monitor');
        $this->artisan('system:monitor');

        $alerts = SystemAlert::where('type', 'SERVER_DISK_LOW')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame(SystemAlert::SEVERITY_CRITICAL, $alerts[0]->severity);
    }

    public function test_enough_free_space_raises_nothing(): void
    {
        config(['backup.disk_min_free_percent' => 0.0]);

        $this->artisan('system:monitor');

        $this->assertSame(0, SystemAlert::where('type', 'SERVER_DISK_LOW')->count());
    }
}
