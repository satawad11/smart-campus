<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Report\ReportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reports (docs/10-module-06-reporting.md "Reports",
 * docs/02-api-contract.md §16). daily() is RPT-007; weekly/monthly/yearly/
 * custom (RPT-008..RPT-011) reuse the same ReportBuilderService sections at
 * a different date-range granularity.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportBuilderService $reportBuilder,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $date = Carbon::parse($validated['date'] ?? now()->toDateString());
        $siteId = $this->resolveSiteId($validated);

        $sections = $this->reportBuilder->buildRangeSections($date, $date, $siteId);
        $sections['by_hour'] = $this->reportBuilder->buildHourlyBreakdown($date, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sections,
            'meta' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSiteId(array $validated): ?int
    {
        if (empty($validated['site_uuid'])) {
            return null;
        }

        return Site::where('uuid', $validated['site_uuid'])->value('id');
    }
}
