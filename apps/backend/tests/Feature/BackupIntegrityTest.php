<?php

namespace Tests\Feature;

use App\Models\BackupJob;
use App\Services\System\BackupService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * PILOT-003: backups must never be recorded as completed or verified without
 * real backup content, and backup:run --verify must fail on an unverified drill.
 */
class BackupIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function tempPath(string $name): string
    {
        $dir = storage_path('app/backups/test');
        File::ensureDirectoryExists($dir);

        return $dir.DIRECTORY_SEPARATOR.$name;
    }

    public function test_failed_pg_dump_throws_and_leaves_no_file(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'pg_dump: command not found', exitCode: 127)]);
        $target = $this->tempPath('failed_dump.sql');

        try {
            app(BackupService::class)->dumpPostgresql($target);
            $this->fail('pg_dump failure must throw');
        } catch (Exception $e) {
            $this->assertStringContainsString('pg_dump failed (exit 127)', $e->getMessage());
        }

        $this->assertFileDoesNotExist($target);
    }

    public function test_successful_exit_without_dump_content_is_rejected(): void
    {
        Process::fake();
        $target = $this->tempPath('empty_dump.sql');
        File::put($target, '');

        $this->expectException(Exception::class);

        try {
            app(BackupService::class)->dumpPostgresql($target);
        } finally {
            $this->assertFileDoesNotExist($target);
        }
    }

    public function test_pg_dump_receives_password_via_environment_not_arguments(): void
    {
        config(['database.connections.pgsql.password' => 'secret-pw']);
        $target = $this->tempPath('ok_dump.sql');

        Process::fake(function () use ($target) {
            File::put($target, "--\n-- PostgreSQL database dump\n--\n");

            return Process::result();
        });

        app(BackupService::class)->dumpPostgresql($target);

        Process::assertRan(function ($process) {
            return ! in_array('secret-pw', (array) $process->command, true)
                && ($process->environment['PGPASSWORD'] ?? null) === 'secret-pw';
        });
        File::delete($target);
    }

    public function test_restore_drill_rejects_non_pg_dump_content_for_pgsql_backups(): void
    {
        $target = $this->tempPath('simulated_dump.sql');
        File::put($target, "-- PostgreSQL backup simulated export for smart_campus\n");

        $job = BackupJob::create([
            'type' => BackupJob::TYPE_POSTGRESQL,
            'target' => $target,
            'status' => BackupJob::STATUS_COMPLETED,
            'checksum' => hash_file('sha256', $target),
            'checksum_algorithm' => 'sha256',
            'metadata' => ['connection' => 'pgsql'],
        ]);

        $drill = app(BackupService::class)->runRestoreDrill($job->id);

        $this->assertSame(BackupJob::STATUS_FAILED, $drill->status);
        $this->assertStringContainsString('not a pg_dump', $drill->error_message);
        $this->assertSame(BackupJob::STATUS_COMPLETED, $job->fresh()->status);
        File::delete($target);
    }

    public function test_storage_backup_includes_object_storage_buckets(): void
    {
        config(['backup.object_storage_disks' => ['face_samples', 'event_snapshots']]);
        Storage::fake('face_samples');
        Storage::fake('event_snapshots');
        Storage::disk('face_samples')->put('profiles/p1/sample-1.jpg', 'sample-bytes');
        Storage::disk('event_snapshots')->put('2026/09/30/e1.jpg', 'snapshot-bytes');

        $job = app(BackupService::class)->runStorageBackup($this->tempPath('objects.zip'));

        $this->assertSame(BackupJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(['face_samples' => 1, 'event_snapshots' => 1], $job->metadata['object_storage']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($job->target));
        $this->assertSame('sample-bytes', $zip->getFromName('face_samples/profiles/p1/sample-1.jpg'));
        $this->assertSame('snapshot-bytes', $zip->getFromName('event_snapshots/2026/09/30/e1.jpg'));
        $zip->close();

        $this->assertSame(BackupJob::STATUS_VERIFIED, app(BackupService::class)->runRestoreDrill($job->id)->status);
        File::delete($job->target);
    }

    public function test_unreadable_object_storage_disk_fails_storage_backup(): void
    {
        config(['backup.object_storage_disks' => ['face_samples']]);
        Storage::shouldReceive('disk')->with('face_samples')->andThrow(new Exception('Unable to list contents'));

        $target = $this->tempPath('unreachable.zip');
        $job = app(BackupService::class)->runStorageBackup($target);

        $this->assertSame(BackupJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('Unable to list contents', $job->error_message);
        $this->assertFileDoesNotExist($target);
    }

    public function test_failed_storage_backup_leaves_no_partial_archive_even_with_local_files(): void
    {
        // Local public files are added to the archive before the object-storage
        // disk fails; the half-built archive must not be written to disk.
        Storage::disk('public')->put('partial-test/file.txt', 'local-bytes');
        $localFile = storage_path('app/public/partial-test/file.txt');
        config(['backup.object_storage_disks' => ['face_samples']]);
        Storage::shouldReceive('disk')->with('face_samples')->andThrow(new Exception('Unable to list contents'));

        $target = $this->tempPath('partial.zip');
        $job = app(BackupService::class)->runStorageBackup($target);

        $this->assertSame(BackupJob::STATUS_FAILED, $job->status);
        $this->assertFileDoesNotExist($target);
        File::deleteDirectory(dirname($localFile));
    }

    public function test_backup_run_verify_fails_when_restore_drill_fails(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $job = new BackupJob(['type' => BackupJob::TYPE_POSTGRESQL, 'status' => BackupJob::STATUS_COMPLETED, 'size_bytes' => 1, 'checksum' => 'x', 'target' => 'x']);
            $job->id = 'job-1';
            $drill = new BackupJob(['status' => BackupJob::STATUS_FAILED, 'error_message' => 'Checksum mismatch']);
            $mock->shouldReceive('runDatabaseBackup')->once()->andReturn($job);
            $mock->shouldReceive('runRestoreDrill')->once()->with('job-1')->andReturn($drill);
        });

        $this->artisan('backup:run', ['--type' => 'POSTGRESQL', '--verify' => true])
            ->assertFailed();
    }
}
