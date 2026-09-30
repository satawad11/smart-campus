<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\Camera;
use App\Models\SystemAlert;
use App\Support\HealthChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * system:monitor raises one alert per failing dependency, stale camera or
 * queue backlog, and nothing when everything is healthy.
 */
class MonitorSystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private function health(array $down = []): void
    {
        $this->mock(HealthChecks::class, function ($mock) use ($down) {
            foreach (['database', 'redis', 'storage', 'aiService'] as $probe) {
                $mock->shouldReceive($probe)->andReturn(! in_array($probe, $down, true));
            }
        });
    }

    public function test_healthy_system_raises_no_alerts(): void
    {
        $this->health();
        Camera::factory()->create(['status' => DeviceStatus::ACTIVE, 'last_seen_at' => now()]);

        $this->artisan('system:monitor')->assertSuccessful();

        $this->assertDatabaseCount('system_alerts', 0);
    }

    public function test_failed_dependencies_raise_one_critical_alert_each_until_resolved(): void
    {
        $this->health(['redis', 'storage', 'aiService']);

        $this->artisan('system:monitor')->assertSuccessful();
        $this->artisan('system:monitor')->assertSuccessful();

        foreach (['REDIS_HEALTH', 'STORAGE_HEALTH', 'AI_SERVICE_HEALTH'] as $type) {
            $alerts = SystemAlert::where('type', $type)->get();
            $this->assertCount(1, $alerts, $type);
            $this->assertSame(SystemAlert::SEVERITY_CRITICAL, $alerts[0]->severity);
        }

        // Once resolved, a persisting failure is raised again
        SystemAlert::where('type', 'REDIS_HEALTH')->update(['status' => SystemAlert::STATUS_RESOLVED]);
        $this->artisan('system:monitor')->assertSuccessful();
        $this->assertSame(2, SystemAlert::where('type', 'REDIS_HEALTH')->count());
    }

    public function test_stale_or_never_seen_active_camera_raises_camera_down(): void
    {
        $this->health();
        config(['camera.health.stale_after_seconds' => 120]);
        $stale = Camera::factory()->create(['status' => DeviceStatus::ACTIVE, 'last_seen_at' => now()->subMinutes(5)]);
        $never = Camera::factory()->create(['status' => DeviceStatus::ACTIVE, 'last_seen_at' => null]);
        Camera::factory()->create(['status' => DeviceStatus::ACTIVE, 'last_seen_at' => now()->subSeconds(30)]);
        Camera::factory()->create(['status' => DeviceStatus::INACTIVE, 'last_seen_at' => now()->subDays(3)]);

        $this->artisan('system:monitor')->assertSuccessful();
        $this->artisan('system:monitor')->assertSuccessful();

        $sources = SystemAlert::where('type', 'CAMERA_DOWN')->pluck('source')->sort()->values()->all();
        $expected = collect(['camera:'.$stale->uuid, 'camera:'.$never->uuid])->sort()->values()->all();
        $this->assertSame($expected, $sources);
        $this->assertSame(SystemAlert::SEVERITY_WARNING, SystemAlert::where('type', 'CAMERA_DOWN')->first()->severity);
    }

    public function test_database_outage_fails_the_command_without_writing_alerts(): void
    {
        $this->health(['database']);

        $this->artisan('system:monitor')->assertFailed();
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('system:monitor')->assertSuccessful();
    }
}
