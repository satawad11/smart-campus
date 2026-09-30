<?php

namespace App\Console\Commands;

use App\Services\System\BackupService;
use Illuminate\Console\Command;

/**
 * Apply the backup retention policy (config backup.retention) to local backup
 * files. Job rows are kept for audit; see BackupService::pruneBackups().
 */
class PruneBackupsCommand extends Command
{
    protected $signature = 'backup:prune {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Delete local backup files outside the retention policy (job rows are kept)';

    public function handle(BackupService $backupService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pruned = $backupService->pruneBackups($dryRun);

        foreach ($pruned as $type => $count) {
            $this->line(sprintf('%s: %d file(s) %s', $type, $count, $dryRun ? 'would be pruned' : 'pruned'));
        }

        return self::SUCCESS;
    }
}
