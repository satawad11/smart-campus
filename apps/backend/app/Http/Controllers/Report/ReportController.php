<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
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
    use EnforcesScope;

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
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $sections = $this->reportBuilder->buildRangeSections($date, $date, $siteId);
        $sections['by_hour'] = $this->reportBuilder->buildHourlyBreakdown($date, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sections,
            'meta' => null,
        ]);
    }

    public function weekly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date'],
            'start_date' => ['sometimes', 'date'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $baseDate = Carbon::parse($validated['date'] ?? $validated['start_date'] ?? now()->toDateString());
        $from = $baseDate->copy()->startOfWeek();
        $to = $baseDate->copy()->endOfWeek();
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $sections = $this->reportBuilder->buildRangeSections($from, $to, $siteId);
        $sections['by_day'] = $this->reportBuilder->buildDailyBreakdown($from, $to, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sections,
            'meta' => null,
        ]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'date' => ['sometimes', 'date'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        if (! empty($validated['month'])) {
            $baseDate = Carbon::parse($validated['month'].'-01');
        } elseif (! empty($validated['date'])) {
            $baseDate = Carbon::parse($validated['date']);
        } else {
            $baseDate = now();
        }

        $from = $baseDate->copy()->startOfMonth();
        $to = $baseDate->copy()->endOfMonth();
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $sections = $this->reportBuilder->buildRangeSections($from, $to, $siteId);
        $sections['by_day'] = $this->reportBuilder->buildDailyBreakdown($from, $to, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sections,
            'meta' => null,
        ]);
    }

    public function yearly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'type' => ['sometimes', 'string', 'in:calendar,academic'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $type = $validated['type'] ?? 'calendar';
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        if ($type === 'academic') {
            // Thai academic year: May 1 to April 30 of following year
            $from = Carbon::create($year, 5, 1)->startOfDay();
            $to = Carbon::create($year + 1, 4, 30)->endOfDay();
        } else {
            $from = Carbon::create($year, 1, 1)->startOfDay();
            $to = Carbon::create($year, 12, 31)->endOfDay();
        }

        $sections = $this->reportBuilder->buildRangeSections($from, $to, $siteId);
        $sections['calendar_type'] = $type;
        $sections['academic_year'] = $type === 'academic' ? $year : null;
        $sections['by_month'] = $this->reportBuilder->buildMonthlyBreakdown($from, $to, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sections,
            'meta' => null,
        ]);
    }

    public function custom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $sections = $this->reportBuilder->buildRangeSections($from, $to, $siteId);
        if ($from->diffInDays($to) <= 31) {
            $sections['by_day'] = $this->reportBuilder->buildDailyBreakdown($from, $to, $siteId);
        } else {
            $sections['by_month'] = $this->reportBuilder->buildMonthlyBreakdown($from, $to, $siteId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sections,
            'meta' => null,
        ]);
    }

    public function review(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $data = $this->reportBuilder->buildReviewReport($from, $to, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $data,
            'meta' => null,
        ]);
    }

    public function unknown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $data = $this->reportBuilder->buildUnknownReport($from, $to, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $data,
            'meta' => null,
        ]);
    }

    public function camera(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);

        $data = $this->reportBuilder->buildCameraReport($from, $to, $siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $data,
            'meta' => null,
        ]);
    }

    public function aiNode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $siteId = $this->resolveScopedSiteId($request, 'report.view', $validated['site_uuid'] ?? null);
        $data = $this->reportBuilder->buildAiNodeReport($siteId);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $data,
            'meta' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
}
