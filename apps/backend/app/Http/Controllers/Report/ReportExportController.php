<?php

namespace App\Http\Controllers\Report;

use App\Enums\AuditAction;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ExportReportJob;
use App\Models\ReportExport;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report exports controller (RPT-013..RPT-017, docs/02-api-contract.md §16).
 */
class ReportExportController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $exports = ReportExport::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $exports->items(),
            'meta' => [
                'current_page' => $exports->currentPage(),
                'last_page' => $exports->lastPage(),
                'per_page' => $exports->perPage(),
                'total' => $exports->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => ['required', 'string', 'in:daily,weekly,monthly,yearly,custom,review,unknown,camera,ai-node,ai_node,history,history_search'],
            'format' => ['required', 'string', 'in:CSV,XLSX,PDF,csv,xlsx,pdf'],
            'filters' => ['sometimes', 'array'],
        ]);

        $format = ReportExportFormat::from(strtoupper($validated['format']));

        $export = ReportExport::create([
            'user_id' => $request->user()->id,
            'report_type' => $validated['report_type'],
            'format' => $format,
            'filters' => $validated['filters'] ?? [],
            'status' => ReportExportStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        ExportReportJob::dispatch($export);

        $this->auditService->record(
            AuditAction::REPORT_EXPORTED,
            $request->user(),
            'REPORT_EXPORT',
            $export->uuid,
            null,
            ['report_type' => $export->report_type, 'format' => $export->format->value],
            ['filters' => $export->filters],
            AuditRequestContext::fromRequest($request),
            'reporting'
        );

        return response()->json([
            'success' => true,
            'message' => 'Export job queued',
            'data' => [
                'uuid' => $export->uuid,
                'report_type' => $export->report_type,
                'format' => $export->format->value,
                'status' => $export->status->value,
                'filters' => $export->filters,
                'created_at' => $export->created_at?->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
            ],
            'meta' => null,
        ], 202);
    }

    public function show(ReportExport $reportExport): JsonResponse
    {
        $downloadUrl = null;
        if ($reportExport->status === ReportExportStatus::COMPLETED && ! $reportExport->isExpired()) {
            $downloadUrl = "/api/v1/reports/exports/{$reportExport->uuid}/download";
        }

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'uuid' => $reportExport->uuid,
                'report_type' => $reportExport->report_type,
                'format' => $reportExport->format->value,
                'status' => $reportExport->status->value,
                'filters' => $reportExport->filters,
                'file_size' => $reportExport->file_size,
                'download_url' => $downloadUrl,
                'error_message' => $reportExport->error_message,
                'started_at' => $reportExport->started_at?->toIso8601String(),
                'completed_at' => $reportExport->completed_at?->toIso8601String(),
                'expires_at' => $reportExport->expires_at?->toIso8601String(),
                'created_at' => $reportExport->created_at?->toIso8601String(),
            ],
            'meta' => null,
        ]);
    }

    public function download(ReportExport $reportExport): StreamedResponse|JsonResponse
    {
        if ($reportExport->status !== ReportExportStatus::COMPLETED || $reportExport->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Export file is not available or has expired',
                'error' => [
                    'code' => 'EXPORT_UNAVAILABLE',
                    'fields' => new \stdClass,
                ],
            ], 410);
        }

        if (! $reportExport->file_path || ! Storage::disk('local')->exists($reportExport->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Export file not found on disk',
                'error' => [
                    'code' => 'NOT_FOUND',
                    'fields' => new \stdClass,
                ],
            ], 404);
        }

        $extension = strtolower($reportExport->format->value);
        $fileName = "report-{$reportExport->report_type}-{$reportExport->uuid}.{$extension}";

        $this->auditService->record(
            AuditAction::REPORT_EXPORT_DOWNLOADED,
            request()->user(),
            'REPORT_EXPORT',
            $reportExport->uuid,
            null,
            null,
            ['report_type' => $reportExport->report_type, 'format' => $reportExport->format->value],
            AuditRequestContext::fromRequest(request()),
            'reporting'
        );

        return Storage::disk('local')->download($reportExport->file_path, $fileName);
    }
}
