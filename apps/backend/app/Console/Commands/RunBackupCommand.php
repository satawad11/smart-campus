<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Services\System\BackupService;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'backup:run 
                            {--type=ALL : Backup type: POSTGRESQL, STORAGE, or ALL}
                            {--verify : Immediately run a restore verification drill on the created backup}';

    protected $description = 'Execute system backup procedures and track backup job metadata';

    public function handle(BackupService $backupService): int
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

        if ($verify) {
            $this->line('Executing restore verification drills...');
            foreach ($jobs as $job) {
                if ($job->status === BackupJob::STATUS_COMPLETED) {
                    $drill = $backupService->runRestoreDrill($job->id);
                    $this->info("Restore drill for job {$job->id}: {$drill->status}");
                }
            }
        }

        $failed = collect($jobs)->contains(fn ($j) => $j->status === BackupJob::STATUS_FAILED);

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
