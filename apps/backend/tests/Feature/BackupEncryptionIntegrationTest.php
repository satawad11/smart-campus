<?php

namespace Tests\Feature;

use App\Models\BackupJob;
use App\Services\System\BackupService;
use App\Support\BackupEncryption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * PDPA G-4: opt-in backup encryption end to end — backup, checksum over
 * the ciphertext, restore drill through decryption, manual decrypt command.
 */
class BackupEncryptionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_keygen')) {
            $this->markTestSkipped('ext-sodium is required (present in the backend image and CI).');
        }
        config(['backup.encryption_key' => BackupEncryption::generateKey()]);
        Storage::disk('public')->put('events/snapshots/enc-test.jpg', 'fake-image-bytes');
    }

    protected function tearDown(): void
    {
        File::delete($this->cleanup);
        Storage::disk('public')->delete('events/snapshots/enc-test.jpg');
        parent::tearDown();
    }

    private function storageBackup(): BackupJob
    {
        $job = app(BackupService::class)->runStorageBackup();
        $this->cleanup[] = (string) $job->target;

        return $job;
    }

    public function test_storage_backup_is_encrypted_and_plaintext_removed(): void
    {
        $job = $this->storageBackup();

        $this->assertSame(BackupJob::STATUS_COMPLETED, $job->status, (string) $job->error_message);
        $this->assertStringEndsWith('.enc', $job->target);
        $this->assertTrue(BackupEncryption::isEncrypted($job->target));
        $this->assertFileDoesNotExist(substr($job->target, 0, -4));
        $this->assertTrue($job->metadata['encrypted']);
        $this->assertSame(hash_file('sha256', $job->target), $job->checksum);
    }

    public function test_restore_drill_verifies_through_decryption_and_leaves_no_temp_file(): void
    {
        $job = $this->storageBackup();

        $drill = app(BackupService::class)->runRestoreDrill($job->id);

        $this->assertSame(BackupJob::STATUS_VERIFIED, $drill->status, (string) $drill->error_message);
        $this->assertSame([], File::glob(storage_path('app/backups/tmp/drill_*')));
    }

    public function test_restore_drill_fails_with_the_wrong_key(): void
    {
        $job = $this->storageBackup();
        config(['backup.encryption_key' => BackupEncryption::generateKey()]);

        $drill = app(BackupService::class)->runRestoreDrill($job->id);

        $this->assertSame(BackupJob::STATUS_FAILED, $drill->status);
        $this->assertStringContainsString('wrong key', (string) $drill->error_message);
        $this->assertSame([], File::glob(storage_path('app/backups/tmp/drill_*')));
    }

    public function test_misconfigured_key_fails_the_backup_without_leaving_plaintext(): void
    {
        config(['backup.encryption_key' => base64_encode('short')]);
        $before = File::glob(storage_path('app/backups/storage/*')) ?: [];

        $job = app(BackupService::class)->runStorageBackup();

        $this->assertSame(BackupJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('encryption', (string) $job->error_message);
        $this->assertSame($before, File::glob(storage_path('app/backups/storage/*')) ?: []);
    }

    public function test_database_backup_is_encrypted_on_sqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('PostgreSQL backups need pg_dump; covered by the storage backup tests.');
        }

        $job = app(BackupService::class)->runDatabaseBackup();
        $this->cleanup[] = (string) $job->target;

        $this->assertStringEndsWith('.enc', $job->target);
        $this->assertSame(BackupJob::STATUS_VERIFIED, app(BackupService::class)->runRestoreDrill($job->id)->status);
    }

    public function test_decrypt_command_restores_a_readable_archive(): void
    {
        $job = $this->storageBackup();
        $out = storage_path('app/backups/decrypted-'.uniqid().'.zip');
        $this->cleanup[] = $out;

        $this->artisan('backup:decrypt', ['source' => $job->target, 'target' => $out])->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out, ZipArchive::CHECKCONS));
        $zip->close();
        $this->artisan('backup:decrypt', ['source' => $job->target, 'target' => $out])->assertFailed(); // no overwrite
        $this->artisan('backup:decrypt', ['source' => $job->target, 'target' => $out.'2', '--key' => BackupEncryption::generateKey()])
            ->assertFailed();
        $this->assertFileDoesNotExist($out.'2');
    }

    public function test_without_a_key_backups_stay_plaintext(): void
    {
        config(['backup.encryption_key' => null]);

        $job = $this->storageBackup();

        $this->assertStringEndsWith('.zip', $job->target);
        $this->assertFalse($job->metadata['encrypted']);
    }
}
