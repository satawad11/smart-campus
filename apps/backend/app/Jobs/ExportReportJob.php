<?php

namespace App\Jobs;

use App\Enums\ReportExportStatus;
use App\Models\Notification;
use App\Models\ReportExport;
use App\Services\Report\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Queued report export job (RPT-014..RPT-017, docs/10-module-06-reporting.md §Exports).
 */
class ExportReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public ReportExport $export
    ) {}

    public function handle(ReportExportService $service): void
    {
        if ($this->export->status === ReportExportStatus::EXPIRED) {
            return;
        }

        $this->export->update([
            'status' => ReportExportStatus::PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $data = $service->extractExportData($this->export);
            $content = $service->render($data, $this->export->format, "Report {$this->export->report_type}");

            $extension = strtolower($this->export->format->value);
            $storagePath = "reports/exports/{$this->export->uuid}.{$extension}";

            Storage::disk('local')->put($storagePath, $content);

            $this->export->update([
                'status' => ReportExportStatus::COMPLETED,
                'file_path' => $storagePath,
                'file_size' => strlen($content),
                'completed_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            Notification::create([
                'user_id' => $this->export->user_id,
                'type' => 'REPORT_EXPORT_COMPLETED',
                'title' => "Report {$this->export->report_type} ready",
                'body' => "Your report export ({$this->export->format->value}) is ready for download.",
                'data' => [
                    'export_uuid' => $this->export->uuid,
                    'report_type' => $this->export->report_type,
                    'format' => $this->export->format->value,
                ],
            ]);
        } catch (Throwable $e) {
            $this->failed($e);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update([
            'status' => ReportExportStatus::FAILED,
            'error_message' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);

        Notification::create([
            'user_id' => $this->export->user_id,
            'type' => 'REPORT_EXPORT_FAILED',
            'title' => "Report {$this->export->report_type} failed",
            'body' => 'Export generation failed: '.($exception?->getMessage() ?? 'Unknown error'),
            'data' => [
                'export_uuid' => $this->export->uuid,
                'report_type' => $this->export->report_type,
                'format' => $this->export->format->value,
            ],
        ]);
    }
}
