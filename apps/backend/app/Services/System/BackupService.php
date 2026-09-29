<?php

namespace App\Services\System;

use App\Models\BackupJob;
use App\Services\Audit\AuditService;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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

        $result = Process::env(['PGPASSWORD' => (string) $password])
            ->timeout(3600)
            ->run(['pg_dump', '-h', (string) $host, '-p', (string) $port, '-U', (string) $username, '-F', 'p', '-f', $targetPath, (string) $database]);

        if (! $result->successful() || ! File::exists($targetPath) || File::size($targetPath) === 0) {
            File::delete($targetPath);
            throw new Exception('pg_dump failed (exit '.$result->exitCode().'): '.trim($result->errorOutput()));
        }
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

        try {
            if (! File::exists($sourceJob->target)) {
                throw new Exception("Backup target file not found: {$sourceJob->target}");
            }

            // Verify checksum
            $calculatedChecksum = hash_file($sourceJob->checksum_algorithm ?? 'sha256', $sourceJob->target);
            if ($calculatedChecksum !== $sourceJob->checksum) {
                throw new Exception("Checksum mismatch: expected {$sourceJob->checksum}, got {$calculatedChecksum}");
            }

            // Verify content based on type
            if ($sourceJob->type === BackupJob::TYPE_STORAGE) {
                $zip = new ZipArchive;
                $res = $zip->open($sourceJob->target, ZipArchive::CHECKCONS);
                if ($res !== true) {
                    throw new Exception("Corrupt zip archive: error code {$res}");
                }
                $zip->close();
            } elseif ($sourceJob->type === BackupJob::TYPE_POSTGRESQL) {
                $head = (string) file_get_contents($sourceJob->target, false, null, 0, 512);
                if ($head === '') {
                    throw new Exception('Database backup file is empty');
                }
                if (($sourceJob->metadata['connection'] ?? null) === 'pgsql'
                    && ! str_contains($head, 'PostgreSQL database dump')) {
                    throw new Exception('Database backup file is not a pg_dump plain-format dump');
                }
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
            $drillJob->update([
                'status' => BackupJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);

            Log::error('Restore drill failed: '.$e->getMessage(), ['drill_job_id' => $drillJob->id]);

            return $drillJob;
        }
    }
}
