<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Models\SystemAlert;
use App\Services\System\AlertLifecycleService;
use App\Services\System\BackupService;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'backup:run 
                            {--type=ALL : Backup type: POSTGRESQL, STORAGE, or ALL}
                            {--verify : Immediately run a restore verification drill on the created backup}';

    protected $description = 'Execute system backup procedures and track backup job metadata';

    public function handle(BackupService $backupService, AlertLifecycleService $alerts): int
    {
        $type = strtoupper($this->option('type') ?: 'ALL');
        $verify = (bool) $this->option('verify');

        $this->info("Starting backup procedure (type: {$type})...");

        $jobs = [];

        if (in_array($type, ['ALL', 'POSTGRESQL', 'DB'])) {
            $this->line('Backing up PostgreSQL database...');
            $job = $backupService->runDatabaseBackup();
            $jobs[] = $job;
            $this->outputJobStatus($job);
        }

        if (in_array($type, ['ALL', 'STORAGE'])) {
            $this->line('Backing up object/file storage...');
            $job = $backupService->runStorageBackup();
            $jobs[] = $job;
            $this->outputJobStatus($job);
        }

        $failed = collect($jobs)->contains(fn ($j) => $j->status === BackupJob::STATUS_FAILED);

        if ($verify) {
            $this->line('Executing restore verification drills...');
            foreach ($jobs as $job) {
                if ($job->status === BackupJob::STATUS_COMPLETED) {
                    $drill = $backupService->runRestoreDrill($job->id);
                    if ($drill->status === BackupJob::STATUS_VERIFIED) {
                        $this->info("Restore drill for job {$job->id}: {$drill->status}");
                    } else {
                        // PILOT-003: --verify must not report success on an unverified backup
                        $failed = true;
                        $this->error("Restore drill for job {$job->id}: {$drill->status} ({$drill->error_message})");
                    }
                }
            }
        }

        // Off-host copy of every verified backup (PILOT-BACKUP-RETENTION-POLICY)
        if (filled(config('backup.offhost_path'))) {
            foreach ($jobs as $job) {
                if ($job->exists) {
                    $job->refresh(); // the drill marks the source job VERIFIED on its own instance
                }
                if ($job->status !== BackupJob::STATUS_VERIFIED) {
                    $this->warn("  -> Not copied off-host (job {$job->id} is {$job->status}; use --verify)");

                    continue;
                }
                try {
                    $backupService->copyOffHost($job);
                    $this->info("  -> Off-host copy verified for job {$job->id}");
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("  -> Off-host copy failed for job {$job->id}: {$e->getMessage()}");
                }
            }
        }

        if ($jobs === []) {
            $this->error("Unknown backup type: {$type}");

            return self::FAILURE;
        }

        if ($failed) {
            // PILOT-008: a failed backup or drill must reach the alert dashboard (SEC-013)
            $alerts->triggerAlert(
                type: 'BACKUP_FAILED',
                severity: SystemAlert::SEVERITY_CRITICAL,
                title: "Backup failed (type: {$type})",
                message: 'backup:run did not produce a '.($verify ? 'verified' : 'completed').' backup. See backup_jobs for details.',
                source: 'backup:run',
                metadata: ['job_ids' => collect($jobs)->pluck('id')->all(), 'verify' => $verify],
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function outputJobStatus(BackupJob $job): void
    {
        if ($job->status === BackupJob::STATUS_COMPLETED) {
            $this->info("  -> Completed: {$job->target} ({$job->size_bytes} bytes, SHA256: {$job->checksum})");
        } else {
            $this->error("  -> Failed: {$job->error_message}");
        }
    }
}
