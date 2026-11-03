<?php

namespace App\Console\Commands;

use App\Models\RmsSyncRun;
use App\Services\Integration\Rms\RmsSyncService;
use Illuminate\Console\Command;

/**
 * RMS API Center -> platform sync (ACR-INT-01). --dry-run computes the full
 * report and writes nothing but the run record. Prints counts and review
 * items (student codes, department names), never names of people.
 */
class RmsSyncCommand extends Command
{
    protected $signature = 'rms:sync
        {--dry-run : Compute the report without writing}
        {--scheduled : Mark the run as started by the scheduler}';

    protected $description = 'Sync students and staff from RMS API Center (use --dry-run first)';

    public function handle(RmsSyncService $service): int
    {
        $run = $service->run($this->option('scheduled') ? 'SCHEDULE' : 'CLI', (bool) $this->option('dry-run'), null);

        if ($run->status === RmsSyncRun::FAILED) {
            $this->error("RMS sync failed: {$run->error}");

            return self::FAILURE;
        }

        $this->info(($run->dry_run ? 'DRY RUN (nothing written)' : 'RMS sync completed')." | run {$run->uuid}");
        $this->table(['Count', 'Value'], array_map(fn ($k, $v) => [$k, $v], array_keys($run->counts ?? []), $run->counts ?? []));
        foreach ($run->issues ?? [] as $issue) {
            $shown = array_slice($issue['items'], 0, 10);
            $more = $issue['count'] > count($shown) ? ' ... (+'.($issue['count'] - count($shown)).')' : '';
            $this->line("{$issue['code']} ({$issue['count']}): ".implode('; ', $shown).$more);
        }

        return self::SUCCESS;
    }
}
