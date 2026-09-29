<?php

namespace App\Console\Commands;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * RPT-017: Clean up expired report exports and their storage artifacts.
 */
class CleanupReportExportsCommand extends Command
{
    protected $signature = 'reports:cleanup-exports';

    protected $description = 'Clean up expired report exports and delete stored files';

    public function handle(): int
    {
        $expiredExports = ReportExport::query()
            ->where('status', '!=', ReportExportStatus::EXPIRED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expiredExports as $export) {
            if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
                Storage::disk('local')->delete($export->file_path);
            }

            $export->update([
                'status' => ReportExportStatus::EXPIRED,
            ]);
            $count++;
        }

        $this->info("Cleaned up {$count} expired report export(s).");

        return self::SUCCESS;
    }
}
