<?php

namespace Tests\Feature;

use App\Models\BackupJob;
use App\Services\System\BackupService;
use Database\Seeders\InitialAdminUserSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pilot backup policy: off-host copies are re-verified, retention keeps the
 * required backups and never deletes job rows, and development-only shared
 * accounts are never seeded in production.
 */
class PilotBackupPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('app/backups/policy-test');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root.'/local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function verifiedJob(string $name, Carbon $completedAt, string $type = BackupJob::TYPE_POSTGRESQL): BackupJob
    {
        $file = $this->root.'/local/'.$name;
        File::put($file, "--\n-- PostgreSQL database dump\n-- {$name}\n");

        return BackupJob::create([
            'type' => $type,
            'status' => BackupJob::STATUS_VERIFIED,
            'target' => $file,
            'checksum' => hash_file('sha256', $file),
            'checksum_algorithm' => 'sha256',
            'started_at' => $completedAt,
            'completed_at' => $completedAt,
            'metadata' => ['connection' => 'pgsql'],
        ]);
    }

    public function test_verified_backup_is_copied_off_host_and_reverified(): void
    {
        config(['backup.offhost_path' => $this->root.'/offhost']);
        $job = $this->verifiedJob('db1.sql', Carbon::now());

        $this->assertTrue(app(BackupService::class)->copyOffHost($job));

        $copy = $job->fresh()->metadata['offhost_file'];
        $this->assertFileExists($copy);
        $this->assertSame($job->checksum, hash_file('sha256', $copy));
        $this->assertStringStartsWith($job->checksum, File::get($copy.'.sha256'));
        $this->assertNotNull($job->fresh()->metadata['offhost_verified_at']);
    }

    public function test_offhost_copy_is_skipped_when_not_configured_and_refused_for_unverified_jobs(): void
    {
        config(['backup.offhost_path' => null]);
        $job = $this->verifiedJob('db2.sql', Carbon::now());
        $this->assertFalse(app(BackupService::class)->copyOffHost($job));

        config(['backup.offhost_path' => $this->root.'/offhost']);
        $job->update(['status' => BackupJob::STATUS_COMPLETED]);
        $this->expectException(\Exception::class);
        app(BackupService::class)->copyOffHost($job);
    }

    public function test_backup_run_fails_and_alerts_when_offhost_target_is_unwritable(): void
    {
        // A file where the off-host directory should be makes the copy impossible
        File::put($this->root.'/blocked', 'not a directory');
        config(['backup.offhost_path' => $this->root.'/blocked']);

        $this->artisan('backup:run', ['--type' => 'POSTGRESQL', '--verify' => true])->assertFailed();

        $this->assertDatabaseHas('system_alerts', ['type' => 'BACKUP_FAILED']);
        BackupJob::whereNotNull('target')->get()->each(fn ($job) => File::delete($job->target));
    }

    public function test_prune_keeps_recent_daily_and_minimum_verified_backups_and_keeps_rows(): void
    {
        config(['backup.retention.recent_hours' => 48, 'backup.retention.daily_days' => 14, 'backup.retention.min_verified' => 3]);

        $recentA = $this->verifiedJob('recent-a.sql', Carbon::now()->subHours(1));
        $recentB = $this->verifiedJob('recent-b.sql', Carbon::now()->subHours(30));
        $day5Late = $this->verifiedJob('day5-late.sql', Carbon::now()->subDays(5)->setTime(23, 0));
        $day5Early = $this->verifiedJob('day5-early.sql', Carbon::now()->subDays(5)->setTime(1, 0));
        $old = $this->verifiedJob('old.sql', Carbon::now()->subDays(40));

        $dry = app(BackupService::class)->pruneBackups(dryRun: true);
        $this->assertSame(2, $dry[BackupJob::TYPE_POSTGRESQL]);
        $this->assertFileExists($old->target);

        $this->artisan('backup:prune')->assertSuccessful();

        foreach ([$recentA, $recentB, $day5Late] as $kept) {
            $this->assertFileExists($kept->target);
        }
        foreach ([$day5Early, $old] as $pruned) {
            $this->assertFileDoesNotExist($pruned->target);
            $this->assertNotNull($pruned->fresh()->metadata['pruned_at']);
            $this->assertSame(BackupJob::STATUS_VERIFIED, $pruned->fresh()->status);
        }
        $this->assertSame(5, BackupJob::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.pruned']);
    }

    public function test_prune_never_goes_below_the_minimum_verified_count(): void
    {
        config(['backup.retention.recent_hours' => 1, 'backup.retention.daily_days' => 1, 'backup.retention.min_verified' => 2]);

        $newest = $this->verifiedJob('a.sql', Carbon::now()->subDays(60));
        $second = $this->verifiedJob('b.sql', Carbon::now()->subDays(61));
        $third = $this->verifiedJob('c.sql', Carbon::now()->subDays(62));

        app(BackupService::class)->pruneBackups();

        $this->assertFileExists($newest->target);
        $this->assertFileExists($second->target);
        $this->assertFileDoesNotExist($third->target);
    }

    public function test_initial_admin_user_seeder_is_a_no_op_in_production(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->app['env'] = 'production';

        // Called directly: db:seed itself would prompt for confirmation in production
        $this->app->make(InitialAdminUserSeeder::class)->run();

        $this->assertDatabaseMissing('users', ['username' => 'superadmin']);
        $this->assertDatabaseMissing('users', ['username' => 'security']);
    }
}
