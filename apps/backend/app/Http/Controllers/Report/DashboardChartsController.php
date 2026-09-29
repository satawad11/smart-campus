<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\Department;
use App\Models\EventDailySummary;
use App\Models\EventHourlySummary;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RPT-006 — dashboard charts (docs/10-module-06-reporting.md "Dashboard"
 * Charts: by hour, by decision, by camera, by department;
 * docs/02-api-contract.md §14). Every chart reads from the RPT-003/004
 * summary tables, never raw AccessEvent — same reasoning as RPT-005's
 * dashboard summary.
 */
class DashboardChartsController extends Controller
{
    public function byHour(Request $request): JsonResponse
    {
        $validated = $this->validateRequest($request, dateOnly: true);
        $date = $validated['date'] ?? now()->toDateString();

        $query = EventHourlySummary::query()->whereDate('hour_bucket', $date);
        $this->applySiteFilter($query, $validated);

        $totals = $query->get()->groupBy(fn ($row) => $row->hour_bucket->format('H:00'))
            ->map(fn ($rows) => (int) $rows->sum('event_count'));

        // Every hour of the day is present, even at 0 — a chart consuming
        // this shouldn't have to fill gaps itself.
        $series = collect(range(0, 23))->map(function (int $hour) use ($totals) {
            $label = sprintf('%02d:00', $hour);

            return ['hour' => $label, 'count' => (int) ($totals[$label] ?? 0)];
        });

        return $this->respond(['date' => $date, 'series' => $series->values()]);
    }

    public function byDecision(Request $request): JsonResponse
    {
        $validated = $this->validateRequest($request);
        $query = $this->dailySummaryQuery($validated);

        $series = $query->get()
            ->groupBy(fn ($row) => $row->decision->value)
            ->map(fn ($rows, $decision) => ['decision' => $decision, 'count' => (int) $rows->sum('event_count')])
            ->values();

        return $this->respond(['from' => $validated['from'], 'to' => $validated['to'], 'series' => $series]);
    }

    public function byCamera(Request $request): JsonResponse
    {
        $validated = $this->validateRequest($request);
        $query = $this->dailySummaryQuery($validated);

        $rows = $query->get()->groupBy('camera_id');
        $cameras = Camera::whereIn('id', $rows->keys()->filter())->get()->keyBy('id');

        $series = $rows->map(function ($group, $cameraId) use ($cameras) {
            $camera = $cameraId !== '' ? $cameras->get((int) $cameraId) : null;

            return [
                'camera_uuid' => $camera?->uuid,
                'camera_name' => $camera?->name ?? 'ไม่ระบุกล้อง',
                'count' => (int) $group->sum('event_count'),
            ];
        })->values();

        return $this->respond(['from' => $validated['from'], 'to' => $validated['to'], 'series' => $series]);
    }

    public function byDepartment(Request $request): JsonResponse
    {
        $validated = $this->validateRequest($request);
        $query = $this->dailySummaryQuery($validated);

        $rows = $query->get()->groupBy('department_id');
        $departments = Department::whereIn('id', $rows->keys()->filter())->get()->keyBy('id');

        $series = $rows->map(function ($group, $departmentId) use ($departments) {
            $department = $departmentId !== '' ? $departments->get((int) $departmentId) : null;

            return [
                'department_uuid' => $department?->uuid,
                'department_name' => $department?->name ?? 'ไม่มีแผนกวิชา',
                'count' => (int) $group->sum('event_count'),
            ];
        })->values();

        return $this->respond(['from' => $validated['from'], 'to' => $validated['to'], 'series' => $series]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRequest(Request $request, bool $dateOnly = false): array
    {
        $rules = $dateOnly
            ? ['date' => ['sometimes', 'date'], 'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid']]
            : [
                'from' => ['sometimes', 'date'],
                'to' => ['sometimes', 'date'],
                'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
            ];

        $validated = $request->validate($rules);

        if (! $dateOnly) {
            $validated['from'] = $validated['from'] ?? now()->toDateString();
            $validated['to'] = $validated['to'] ?? now()->toDateString();
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dailySummaryQuery(array $validated)
    {
        $query = EventDailySummary::query()
            ->whereDate('summary_date', '>=', $validated['from'])
            ->whereDate('summary_date', '<=', $validated['to']);

        $this->applySiteFilter($query, $validated);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applySiteFilter($query, array $validated): void
    {
        if (! empty($validated['site_uuid'])) {
            $siteId = Site::where('uuid', $validated['site_uuid'])->value('id');
            $query->where('site_id', $siteId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $data,
            'meta' => null,
        ]);
    }
}
