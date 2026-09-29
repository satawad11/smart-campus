<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Services\System\BackupService;
use Illuminate\Console\Command;

class RunRestoreDrillCommand extends Command
{
    protected $signature = 'backup:restore-drill 
                            {job_id? : ID of specific BackupJob to test restorability}
                            {--latest : Test the latest completed backup job}';

    protected $description = 'Execute restore drill to verify checksum integrity and archive structure';

    public function handle(BackupService $backupService): int
    {
        $jobId = $this->argument('job_id');

        if (! $jobId && $this->option('latest')) {
            $latest = BackupJob::whereIn('status', [BackupJob::STATUS_COMPLETED, BackupJob::STATUS_VERIFIED])
                ->latest()
                ->first();
            $jobId = $latest?->id;
        }

        if (! $jobId) {
            $this->error('Please provide a job_id or use --latest flag.');

            return self::FAILURE;
        }

        $this->info("Starting restore drill for BackupJob [{$jobId}]...");

        $drill = $backupService->runRestoreDrill($jobId);

        if ($drill->status === BackupJob::STATUS_VERIFIED) {
            $this->info("SUCCESS: Restore drill verified backup integrity [{$drill->id}]. Checksum matches ({$drill->checksum}).");

            return self::SUCCESS;
        }

        $this->error("FAILED: Restore drill failed [{$drill->id}]: {$drill->error_message}");

        return self::FAILURE;
    }
}
