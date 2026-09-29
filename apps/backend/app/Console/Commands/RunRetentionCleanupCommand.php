<?php

namespace App\Console\Commands;

use App\Services\System\RetentionCleanupService;
use Illuminate\Console\Command;

class RunRetentionCleanupCommand extends Command
{
    protected $signature = 'retention:cleanup 
                            {--dry-run : Simulate retention pruning without deleting records or files}';

    protected $description = 'Prune historical events, snapshots, logs, and report files according to retention settings';

    public function handle(RetentionCleanupService $retentionService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Running retention cleanup in DRY-RUN mode (no deletions will occur)...');
        } else {
            $this->info('Executing retention cleanup policies...');
        }

        $results = $retentionService->runAllRetentionPolicies($dryRun);

        $this->table(
            ['Category', $dryRun ? 'Candidates' : 'Pruned Count'],
            collect($results)->map(fn ($count, $cat) => [$cat, $count])->toArray()
        );

        $total = array_sum($results);
        $this->info("Retention cleanup finished. Total items processed: {$total}");

        return self::SUCCESS;
    }
}
