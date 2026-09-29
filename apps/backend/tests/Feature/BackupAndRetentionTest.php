<?php

namespace Tests\Feature;

use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use App\Models\BackupJob;
use App\Models\Camera;
use App\Models\CameraHealthLog;
use App\Models\Site;
use App\Models\Zone;
use App\Services\System\BackupService;
use App\Services\System\RetentionCleanupService;
use App\Services\System\SystemSettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_backup_service_creates_database_backup_and_tracks_job(): void
    {
        /** @var BackupService $backupService */
        $backupService = app(BackupService::class);

        $job = $backupService->runDatabaseBackup();

        $this->assertDatabaseHas('backup_jobs', [
            'id' => $job->id,
            'type' => BackupJob::TYPE_POSTGRESQL,
            'status' => BackupJob::STATUS_COMPLETED,
        ]);

        $this->assertNotNull($job->checksum);
        $this->assertNotNull($job->size_bytes);
        $this->assertFileExists($job->target);

        // Run restore drill on this job
        $drill = $backupService->runRestoreDrill($job->id);
        $this->assertSame(BackupJob::STATUS_VERIFIED, $drill->status);
        $this->assertSame($job->checksum, $drill->checksum);

        // Clean up created test backup file
        if (File::exists($job->target)) {
            File::delete($job->target);
        }
    }

    public function test_backup_service_creates_storage_backup_and_verifies_archive(): void
    {
        /** @var BackupService $backupService */
        $backupService = app(BackupService::class);

        // Put a dummy public file
        Storage::disk('public')->put('events/snapshots/test.jpg', 'fake-image-bytes');

        $job = $backupService->runStorageBackup();

        $this->assertDatabaseHas('backup_jobs', [
            'id' => $job->id,
            'type' => BackupJob::TYPE_STORAGE,
            'status' => BackupJob::STATUS_COMPLETED,
        ]);

        $this->assertNotNull($job->checksum);
        $this->assertFileExists($job->target);

        // Run restore drill on storage backup
        $drill = $backupService->runRestoreDrill($job->id);
        $this->assertSame(BackupJob::STATUS_VERIFIED, $drill->status);

        // Clean up
        if (File::exists($job->target)) {
            File::delete($job->target);
        }
        Storage::disk('public')->delete('events/snapshots/test.jpg');
    }

    public function test_artisan_backup_and_restore_drill_commands(): void
    {
        $this->artisan('backup:run', ['--type' => 'POSTGRESQL', '--verify' => true])
            ->assertSuccessful();

        $latestJob = BackupJob::where('type', BackupJob::TYPE_POSTGRESQL)->latest()->first();
        $this->assertNotNull($latestJob);

        $this->artisan('backup:restore-drill', ['--latest' => true])
            ->assertSuccessful();

        // Clean up file
        if ($latestJob->target && File::exists($latestJob->target)) {
            File::delete($latestJob->target);
        }
    }

    public function test_retention_cleanup_service_prunes_aged_records(): void
    {
        /** @var SystemSettingService $settingsService */
        $settingsService = app(SystemSettingService::class);
        $settingsService->updateGroup('RETENTION', [
            'retention.camera_health_days' => 14,
            'retention.ai_node_health_days' => 14,
            'retention.report_exports_days' => 7,
        ]);

        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $camera = Camera::factory()->for($site)->for($zone)->create();
        $aiNode = AiNode::factory()->for($site)->create();

        // Create aged camera health log (20 days old)
        CameraHealthLog::create([
            'camera_id' => $camera->id,
            'status' => 'ONLINE',
            'recorded_at' => Carbon::now()->subDays(20),
        ]);

        // Create recent camera health log (2 days old)
        CameraHealthLog::create([
            'camera_id' => $camera->id,
            'status' => 'ONLINE',
            'recorded_at' => Carbon::now()->subDays(2),
        ]);

        // Create aged AI node health log (20 days old)
        AiNodeHealthLog::create([
            'ai_node_id' => $aiNode->id,
            'status' => 'ONLINE',
            'recorded_at' => Carbon::now()->subDays(20),
        ]);

        /** @var RetentionCleanupService $retentionService */
        $retentionService = app(RetentionCleanupService::class);

        // Dry run
        $dryResults = $retentionService->runAllRetentionPolicies(dryRun: true);
        $this->assertGreaterThanOrEqual(1, $dryResults['camera_health']);
        $this->assertGreaterThanOrEqual(1, $dryResults['ai_node_health']);
        $this->assertEquals(2, CameraHealthLog::count());

        // Actual execution
        $realResults = $retentionService->runAllRetentionPolicies(dryRun: false);
        $this->assertGreaterThanOrEqual(1, $realResults['camera_health']);
        $this->assertGreaterThanOrEqual(1, $realResults['ai_node_health']);

        // Check that recent log remains and aged log was pruned
        $this->assertEquals(1, CameraHealthLog::count());
        $this->assertEquals(0, AiNodeHealthLog::count());

        // Test artisan command
        $this->artisan('retention:cleanup', ['--dry-run' => true])
            ->assertSuccessful();
    }
}
