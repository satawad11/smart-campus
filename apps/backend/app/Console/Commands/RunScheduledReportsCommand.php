<?php

namespace App\Console\Commands;

use App\Enums\ReportExportStatus;
use App\Jobs\ExportReportJob;
use App\Models\ReportExport;
use App\Models\ScheduledReport;
use Illuminate\Console\Command;

/**
 * RPT-018: Run scheduled reports on cron schedule.
 */
class RunScheduledReportsCommand extends Command
{
    protected $signature = 'reports:run-scheduled';

    protected $description = 'Process due scheduled reports and queue export jobs';

    public function handle(): int
    {
        $dueReports = ScheduledReport::query()
            ->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->get();

        $queued = 0;
        foreach ($dueReports as $scheduled) {
            $export = ReportExport::create([
                'user_id' => $scheduled->created_by,
                'report_type' => $scheduled->report_type,
                'format' => $scheduled->format,
                'filters' => $scheduled->filters ?? [],
                'status' => ReportExportStatus::PENDING,
                'expires_at' => now()->addDays(7),
            ]);

            ExportReportJob::dispatch($export);

            $nextRun = match (strtoupper($scheduled->schedule_type)) {
                'DAILY' => now()->addDay()->startOfDay(),
                'WEEKLY' => now()->addWeek()->startOfDay(),
                'MONTHLY' => now()->addMonth()->startOfDay(),
                default => now()->addDay(),
            };

            $scheduled->update([
                'last_run_at' => now(),
                'next_run_at' => $nextRun,
            ]);

            $queued++;
        }

        $this->info("Queued {$queued} scheduled report export job(s).");

        return self::SUCCESS;
    }
}
