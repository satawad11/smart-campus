<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Staff\StaffImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Queued processing of a validated staff import (Module 09, PPL-004).
 */
class ProcessStaffImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public ImportJob $importJob
    ) {}

    public function handle(StaffImportService $service): void
    {
        $service->process($this->importJob);
    }

    public function failed(?Throwable $exception): void
    {
        app(StaffImportService::class)->handleFailure($this->importJob, $exception);
    }
}
