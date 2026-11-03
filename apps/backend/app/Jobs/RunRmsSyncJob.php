<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Integration\Rms\RmsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * On-demand RMS sync started from the UI (ACR-INT-01). The service records
 * its own run row, lock and audit; a failure is reported in that row.
 */
class RunRmsSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public bool $dryRun,
        public ?int $userId,
    ) {}

    public function handle(RmsSyncService $service): void
    {
        $service->run('MANUAL', $this->dryRun, $this->userId !== null ? User::find($this->userId) : null);
    }
}
