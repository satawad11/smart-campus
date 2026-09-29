<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\AiNode;
use App\Models\BackupJob;
use App\Models\SystemAlert;
use App\Services\System\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PILOT-008: operational failures must raise system alerts automatically
 * (SEC-012 lifecycle, SEC-013 routing table) instead of only being logged.
 */
class PilotAlertProducersTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_ai_node_raises_one_critical_alert_per_stale_period(): void
    {
        config(['ai_node.health.stale_after_seconds' => 60]);
        $node = AiNode::factory()->create([
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->artisan('ai-nodes:detect-stale')->assertExitCode(0);
        $this->artisan('ai-nodes:detect-stale')->assertExitCode(0);

        $alerts = SystemAlert::where('type', 'AI_NODE_DOWN')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame(SystemAlert::SEVERITY_CRITICAL, $alerts[0]->severity);
        $this->assertSame(SystemAlert::STATUS_OPEN, $alerts[0]->status);
        $this->assertSame('ai_node:'.$node->uuid, $alerts[0]->source);
    }

    public function test_healthy_ai_node_raises_no_alert(): void
    {
        config(['ai_node.health.stale_after_seconds' => 90]);
        AiNode::factory()->create([
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => now()->subSeconds(10),
        ]);

        $this->artisan('ai-nodes:detect-stale')->assertExitCode(0);

        $this->assertDatabaseCount('system_alerts', 0);
    }

    public function test_failed_backup_raises_critical_alert(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('runDatabaseBackup')->once()->andReturn(new BackupJob([
                'type' => BackupJob::TYPE_POSTGRESQL,
                'status' => BackupJob::STATUS_FAILED,
                'error_message' => 'pg_dump failed (exit 1)',
            ]));
        });

        $this->artisan('backup:run', ['--type' => 'POSTGRESQL'])->assertFailed();

        $alert = SystemAlert::where('type', 'BACKUP_FAILED')->first();
        $this->assertNotNull($alert);
        $this->assertSame(SystemAlert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_successful_backup_raises_no_alert(): void
    {
        $this->artisan('backup:run', ['--type' => 'POSTGRESQL', '--verify' => true])->assertSuccessful();

        $this->assertDatabaseCount('system_alerts', 0);
        BackupJob::whereNotNull('target')->get()->each(fn ($job) => @unlink($job->target));
    }

    public function test_unknown_backup_type_fails(): void
    {
        $this->artisan('backup:run', ['--type' => 'NOPE'])->assertFailed();

        $this->assertDatabaseCount('backup_jobs', 0);
    }
}
