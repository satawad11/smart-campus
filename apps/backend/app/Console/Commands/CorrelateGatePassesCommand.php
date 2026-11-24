<?php

namespace App\Console\Commands;

use App\Services\Gate\GateCorrelationService;
use App\Services\System\FeatureFlagService;
use Illuminate\Console\Command;

/**
 * GATE-004: close Smart Gate passes whose correlation window has passed (FACE_ONLY /
 * PLATE_ONLY), and pick up pairings missed at ingest time. Scheduled every five seconds.
 */
class CorrelateGatePassesCommand extends Command
{
    protected $signature = 'gates:correlate';

    protected $description = 'Close Smart Gate passes whose correlation window has passed';

    public function handle(FeatureFlagService $features, GateCorrelationService $correlation): int
    {
        if (! $features->isEnabled('vehicle')) {
            $this->info('Vehicle feature is disabled; gate correlation was skipped.');

            return self::SUCCESS;
        }

        $count = $correlation->closeExpired();
        $this->info("Created {$count} gate pass(es).");

        return self::SUCCESS;
    }
}
