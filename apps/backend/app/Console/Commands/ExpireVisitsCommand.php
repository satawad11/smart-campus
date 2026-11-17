<?php

namespace App\Console\Commands;

use App\Services\People\VisitService;
use App\Services\System\FeatureFlagService;
use Illuminate\Console\Command;

class ExpireVisitsCommand extends Command
{
    protected $signature = 'visits:expire';

    protected $description = 'Expire scheduled visitor visits after their valid window';

    public function handle(FeatureFlagService $features, VisitService $visits): int
    {
        if (! $features->isEnabled('visitor')) {
            $this->info('Visitor feature is disabled; visit expiry was skipped.');

            return self::SUCCESS;
        }

        $count = $visits->expireScheduled();
        $this->info("Expired {$count} scheduled visit(s).");

        return self::SUCCESS;
    }
}
