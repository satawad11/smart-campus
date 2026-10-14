<?php

namespace App\Services\System;

use App\Models\BackupJob;
use App\Services\Audit\AuditService;
use App\Support\BackupEncryption;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class BackupService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Run PostgreSQL database backup procedure.
     */
    public function runDatabaseBackup(?string $customPath = null): BackupJob
    {
        $job = BackupJob::create([
            'type' => BackupJob::TYPE_POSTGRESQL,
            'status' => BackupJob::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            $connection = config('database.default');
            $backupDir = storage_path('app/backups/db');
            if (! File::isDirectory($backupDir)) {
                File::makeDirectory($backupDir, 0750, true);
            }

            $timestamp = Carbon::now()->format('Ymd_His');
            $filename = "db_backup_{$connection}_{$timestamp}.sql";
            $targetPath = $customPath ?? ($backupDir.DIRECTORY_SEPARATOR.$filename);

            if ($connection === 'sqlite') {
                $dbPath = config('database.connections.sqlite.database');
                if ($dbPath === ':memory:' || empty($dbPath)) {
                    // Export sqlite memory schema and table dumps
                    $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                    $sqlContent = "-- SQLite In-Memory Backup {$timestamp}\n";
                    foreach ($tables as $t) {
                        $tableName = $t->name;
                        $createStmt = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$tableName]);
                        if ($createStmt && isset($createStmt->sql)) {
                            $sqlContent .= $createStmt->sql.";\n";
                        }
                    }
                    File::put($targetPath, $sqlContent);
                } else {
                    File::copy($dbPath, $targetPath);
                }
            } else {
                $this->dumpPostgresql($targetPath);
            }

            [$targetPath, $encrypted] = $this->finalizeFile($targetPath);

            $size = File::size($targetPath);
            $checksum = hash_file('sha256', $targetPath);

            $job->update([
                'target' => $targetPath,
                'status' => BackupJob::STATUS_COMPLETED,
                'size_bytes' => $size,
                'checksum' => $checksum,
                'checksum_algorithm' => 'sha256',
                'completed_at' => Carbon::now(),
                'metadata' => [
                    'connection' => $connection,
                    'file' => basename($targetPath),
                    'encrypted' => $encrypted,
                ],
            ]);

            $this->auditService->record(
                action: 'backup.created',
                actor: null,
                subjectType: 'backup_job',
                subjectUuid: $job->id,
                oldValues: null,
                newValues: ['type' => $job->type, 'target' => basename($targetPath), 'size_bytes' => $size],
                metadata: null,
                module: 'backup'
            );

            return $job;
        } catch (Exception $e) {
            $job->update([
                'status' => BackupJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);

            Log::error('Backup failed: '.$e->getMessage(), ['job_id' => $job->id]);

            return $job;
        }
    }

    /**
     * Dump the PostgreSQL database to a plain-format SQL file with pg_dump.
     *
     * PILOT-003: a failed or missing pg_dump must fail the job — an empty or
     * simulated file is never recorded as a completed backup. The password is
     * passed via the process environment, not the command line.
     *
     * @throws Exception
     */
    public function dumpPostgresql(string $targetPath): void
    {
        $host = config('database.connections.pgsql.host', '127.0.0.1');
        $port = config('database.connections.pgsql.port', '5432');
        $database = config('database.connections.pgsql.database', 'smart_campus');
        $username = config('database.connections.pgsql.username', 'smart_campus');
        $password = config('database.connections.pgsql.password', '');

        // SEC-005: prefer the read-only backup role when configured
        if (filled(config('backup.database.username'))) {
            $username = config('backup.database.username');
            $password = config('backup.database.password', '');
        }

        $result = Process::env(['PGPASSWORD' => (string) $password])
            ->timeout(3600)
            ->run(['pg_dump', '-h', (string) $host, '-p', (string) $port, '-U', (string) $username, '-F', 'p', '-f', $targetPath, (string) $database]);

        if (! $result->successful() || ! File::exists($targetPath) || File::size($targetPath) === 0) {
            File::delete($targetPath);
            throw new Exception('pg_dump failed (exit '.$result->exitCode().'): '.trim($result->errorOutput()));
        }

        @chmod($targetPath, 0600);
    }

    /**
     * Run storage directory / objects backup procedure.
     */
    public function runStorageBackup(?string $customPath = null): BackupJob
    {
        $job = BackupJob::create([
            'type' => BackupJob::TYPE_STORAGE,
            'status' => BackupJob::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $zip = null;
        $zipOpen = false;
        $targetPath = null;

        try {
            $backupDir = storage_path('app/backups/storage');
            if (! File::isDirectory($backupDir)) {
                File::makeDirectory($backupDir, 0750, true);
            }

            $timestamp = Carbon::now()->format('Ymd_His');
            $filename = "storage_backup_{$timestamp}.zip";
            $targetPath = $customPath ?? ($backupDir.DIRECTORY_SEPARATOR.$filename);

            $sourcePath = storage_path('app/public');
            if (! File::isDirectory($sourcePath)) {
                File::makeDirectory($sourcePath, 0755, true);
            }

            $zip = new ZipArchive;
            if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("Unable to create storage archive: {$targetPath}");
            }
            $zipOpen = true;

            $files = File::allFiles($sourcePath);
            foreach ($files as $file) {
                $relativePath = substr($file->getPathname(), strlen($sourcePath) + 1);
                $zip->addFile($file->getPathname(), $relativePath);
            }

            // PILOT-003: include object-storage buckets (face samples, person photos,
            // event snapshots); an unreadable disk fails the job instead of being skipped.
            $objectCounts = [];
            foreach (config('backup.object_storage_disks', []) as $disk) {
                $objectCounts[$disk] = 0;
                foreach (Storage::disk($disk)->allFiles() as $objectPath) {
                    $contents = Storage::disk($disk)->get($objectPath);
                    if ($contents === null) {
                        throw new Exception("Unable to read object {$disk}/{$objectPath}");
                    }
                    $zip->addFromString($disk.'/'.$objectPath, $contents);
                    $objectCounts[$disk]++;
                }
            }

            $zip->addFromString('manifest.json', json_encode([
                'created_at' => Carbon::now()->toIso8601String(),
                'file_count' => count($files),
                'object_storage' => $objectCounts,
            ]));
            $zip->close();
            $zipOpen = false;
            @chmod($targetPath, 0600);

            [$targetPath, $encrypted] = $this->finalizeFile($targetPath);

            $size = File::size($targetPath);
            $checksum = hash_file('sha256', $targetPath);

            $job->update([
                'target' => $targetPath,
                'status' => BackupJob::STATUS_COMPLETED,
                'size_bytes' => $size,
                'checksum' => $checksum,
                'checksum_algorithm' => 'sha256',
                'completed_at' => Carbon::now(),
                'metadata' => [
                    'source' => $sourcePath,
                    'file' => basename($targetPath),
                    'object_storage' => $objectCounts,
                    'encrypted' => $encrypted,
                ],
            ]);

            $this->auditService->record(
                action: 'backup.created',
                actor: null,
                subjectType: 'backup_job',
                subjectUuid: $job->id,
                oldValues: null,
                newValues: ['type' => $job->type, 'target' => basename($targetPath), 'size_bytes' => $size],
                metadata: null,
                module: 'backup'
            );

            return $job;
        } catch (Exception $e) {
            // Discard the half-built archive: an open ZipArchive would otherwise be
            // written to disk when destroyed, leaving a partial "backup" behind.
            if ($zipOpen && $zip instanceof ZipArchive) {
                $zip->unchangeAll();
                @$zip->close();
            }
            if (is_string($targetPath)) {
                File::delete($targetPath);
            }

            $job->update([
                'status' => BackupJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);

            Log::error('Storage backup failed: '.$e->getMessage(), ['job_id' => $job->id]);

            return $job;
        }
    }

    /**
     * Run a restore drill to verify a backup's integrity and restorability.
     */
    public function runRestoreDrill(string $backupJobId): BackupJob
    {
        $sourceJob = BackupJob::findOrFail($backupJobId);

        $drillJob = BackupJob::create([
            'type' => BackupJob::TYPE_RESTORE_DRILL,
            'target' => $sourceJob->target,
            'status' => BackupJob::STATUS_RUNNING,
            'started_at' => Carbon::now(),
            'metadata' => ['source_job_id' => $sourceJob->id],
        ]);

        $tempPath = null;

        try {
            if (! File::exists($sourceJob->target)) {
                throw new Exception("Backup target file not found: {$sourceJob->target}");
            }

            // Verify checksum
            $calculatedChecksum = hash_file($sourceJob->checksum_algorithm ?? 'sha256', $sourceJob->target);
            if ($calculatedChecksum !== $sourceJob->checksum) {
                throw new Exception("Checksum mismatch: expected {$sourceJob->checksum}, got {$calculatedChecksum}");
            }

            // PDPA G-4: an encrypted backup is verified by decrypting it (which
            // also authenticates every chunk) into a temporary file.
            $verifyPath = $sourceJob->target;
            if (BackupEncryption::isEncrypted($sourceJob->target)) {
                $key = BackupEncryption::keyFromConfig(config('backup.encryption_key'));
                if ($key === null) {
                    throw new Exception('Backup is encrypted but BACKUP_ENCRYPTION_KEY is not configured');
                }
                $tempDir = storage_path('app/backups/tmp');
                File::ensureDirectoryExists($tempDir, 0700);
                $tempPath = $tempDir.DIRECTORY_SEPARATOR.'drill_'.$drillJob->id;
                BackupEncryption::decryptFile($sourceJob->target, $tempPath, $key);
                $verifyPath = $tempPath;
            }

            // Verify content based on type
            if ($sourceJob->type === BackupJob::TYPE_STORAGE) {
                $zip = new ZipArchive;
                $res = $zip->open($verifyPath, ZipArchive::CHECKCONS);
                if ($res !== true) {
                    throw new Exception("Corrupt zip archive: error code {$res}");
                }
                $zip->close();
            } elseif ($sourceJob->type === BackupJob::TYPE_POSTGRESQL) {
                $head = (string) file_get_contents($verifyPath, false, null, 0, 512);
                if ($head === '') {
                    throw new Exception('Database backup file is empty');
                }
                if (($sourceJob->metadata['connection'] ?? null) === 'pgsql'
                    && ! str_contains($head, 'PostgreSQL database dump')) {
                    throw new Exception('Database backup file is not a pg_dump plain-format dump');
                }
            }

            if ($tempPath !== null) {
                File::delete($tempPath);
            }

            $drillJob->update([
                'status' => BackupJob::STATUS_VERIFIED,
                'size_bytes' => File::size($sourceJob->target),
                'checksum' => $calculatedChecksum,
                'checksum_algorithm' => $sourceJob->checksum_algorithm ?? 'sha256',
                'completed_at' => Carbon::now(),
            ]);

            $sourceJob->update(['status' => BackupJob::STATUS_VERIFIED]);

            $this->auditService->record(
                action: 'backup.restore_drill_verified',
                actor: null,
                subjectType: 'backup_job',
                subjectUuid: $drillJob->id,
                oldValues: null,
                newValues: ['source_job_id' => $sourceJob->id, 'status' => 'VERIFIED'],
                metadata: null,
                module: 'backup'
            );

            return $drillJob;
        } catch (Exception $e) {
            if ($tempPath !== null) {
                File::delete($tempPath);
            }

            $drillJob->update([
                'status' => BackupJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);

            Log::error('Restore drill failed: '.$e->getMessage(), ['drill_job_id' => $drillJob->id]);

            return $drillJob;
        }
    }

    /**
     * PDPA G-4: when BACKUP_ENCRYPTION_KEY is set, replace the plaintext
     * backup with its encrypted form (<file>.enc). A misconfigured key fails
     * the backup rather than silently leaving plaintext; on any failure both
     * files are removed.
     *
     * @return array{0: string, 1: bool} final path, whether it is encrypted
     */
    private function finalizeFile(string $path): array
    {
        $configured = config('backup.encryption_key');
        if ($configured === null || trim((string) $configured) === '') {
            return [$path, false];
        }

        $encryptedPath = $path.'.enc';
        try {
            $key = BackupEncryption::keyFromConfig((string) $configured);
            BackupEncryption::encryptFile($path, $encryptedPath, $key);
        } catch (Throwable $e) {
            File::delete($encryptedPath);

            throw new Exception('Backup encryption failed: '.$e->getMessage(), 0, $e);
        } finally {
            File::delete($path);
        }

        return [$encryptedPath, true];
    }

    /**
     * Copy a verified backup to the off-host directory with a .sha256 sidecar and
     * re-verify the copy. Returns false when no off-host path is configured.
     *
     * @throws Exception when the copy cannot be written or does not match
     */
    public function copyOffHost(BackupJob $job): bool
    {
        $base = config('backup.offhost_path');
        if (blank($base)) {
            return false;
        }

        if ($job->status !== BackupJob::STATUS_VERIFIED || ! is_string($job->target) || ! File::exists($job->target)) {
            throw new Exception("Backup job {$job->id} is not a verified backup with a local file");
        }

        $dir = rtrim((string) $base, '/').DIRECTORY_SEPARATOR.strtolower($job->type);
        File::ensureDirectoryExists($dir, 0750);
        $copy = $dir.DIRECTORY_SEPARATOR.basename($job->target);

        if (! @copy($job->target, $copy)) {
            throw new Exception("Unable to copy backup to off-host path {$dir}");
        }
        @chmod($copy, 0600);

        $algorithm = $job->checksum_algorithm ?: 'sha256';
        if (hash_file($algorithm, $copy) !== $job->checksum) {
            File::delete($copy);
            throw new Exception("Off-host copy checksum mismatch for backup job {$job->id}");
        }
        File::put($copy.'.'.$algorithm, $job->checksum.'  '.basename($copy).PHP_EOL);

        $job->update(['metadata' => array_merge($job->metadata ?? [], [
            'offhost_file' => $copy,
            'offhost_verified_at' => Carbon::now()->toIso8601String(),
        ])]);

        return true;
    }

    /**
     * Apply the backup retention policy to local backup files (config backup.retention).
     *
     * Keeps every backup completed within `recent_hours`, the newest verified backup
     * of each day for `daily_days`, and at least `min_verified` verified backups per
     * type. Pruned jobs keep their rows (audit history); only the local file is
     * removed and `metadata.pruned_at` is set. Off-host copies are governed by the
     * off-host target's own retention.
     *
     * @return array<string, int> files pruned per type
     */
    public function pruneBackups(bool $dryRun = false): array
    {
        $recentCutoff = Carbon::now()->subHours((int) config('backup.retention.recent_hours', 48));
        $dailyCutoff = Carbon::now()->subDays((int) config('backup.retention.daily_days', 14))->startOfDay();
        $minVerified = max(1, (int) config('backup.retention.min_verified', 3));
        $pruned = [];

        foreach ([BackupJob::TYPE_POSTGRESQL, BackupJob::TYPE_STORAGE] as $type) {
            $jobs = BackupJob::query()
                ->where('type', $type)
                ->whereIn('status', [BackupJob::STATUS_COMPLETED, BackupJob::STATUS_VERIFIED])
                ->whereNotNull('target')
                ->orderByDesc('completed_at')
                ->get()
                ->filter(fn (BackupJob $job) => empty($job->metadata['pruned_at']));

            $keep = [];
            $verified = $jobs->where('status', BackupJob::STATUS_VERIFIED);
            foreach ($verified->take($minVerified) as $job) {
                $keep[$job->id] = true;
            }
            foreach ($jobs as $job) {
                if ($job->completed_at !== null && $job->completed_at->greaterThanOrEqualTo($recentCutoff)) {
                    $keep[$job->id] = true;
                }
            }
            $seenDays = [];
            foreach ($verified as $job) {
                $day = $job->completed_at?->toDateString();
                if ($day !== null && $job->completed_at->greaterThanOrEqualTo($dailyCutoff) && ! isset($seenDays[$day])) {
                    $seenDays[$day] = true;
                    $keep[$job->id] = true;
                }
            }

            $pruned[$type] = 0;
            foreach ($jobs as $job) {
                if (isset($keep[$job->id])) {
                    continue;
                }
                $pruned[$type]++;
                if ($dryRun) {
                    continue;
                }
                File::delete($job->target);
                $job->update(['metadata' => array_merge($job->metadata ?? [], [
                    'pruned_at' => Carbon::now()->toIso8601String(),
                ])]);
            }
        }

        if (! $dryRun && array_sum($pruned) > 0) {
            $this->auditService->record(
                action: 'backup.pruned',
                actor: null,
                subjectType: 'backup_job',
                subjectUuid: null,
                oldValues: null,
                newValues: $pruned,
                metadata: null,
                module: 'backup'
            );
        }

        return $pruned;
    }
}
