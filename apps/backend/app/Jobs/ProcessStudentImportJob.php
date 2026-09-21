<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Student\StudentImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Process student import background job (STD-013).
 *
 * Orchestrates execution of a previously validated ImportJob.
 * Strictly uses StudentService for student creation and never creates
 * domain records directly.
 */
class ProcessStudentImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public ImportJob $importJob
    ) {}

    public function handle(StudentImportService $service): void
    {
        $service->processImportJob($this->importJob);
    }

    public function failed(?Throwable $exception): void
    {
        app(StudentImportService::class)->handleCatastrophicFailure($this->importJob, $exception);
    }
}
