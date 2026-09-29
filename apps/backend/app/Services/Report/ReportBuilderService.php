<?php

namespace App\Services\Report;

use App\Models\Camera;
use App\Models\Department;
use App\Models\EventDailySummary;
use App\Models\EventHourlySummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared report-section builder for RPT-007..RPT-011 (daily/weekly/monthly/
 * yearly/custom reports) — every one of them is the same shape (totals +
 * by-decision + by-camera + by-department breakdown) over a different date
 * range granularity, so this is built once and reused rather than
 * reimplemented per report. Reads from EventDailySummary/EventHourlySummary
 * (RPT-003/004) only, same reasoning as the dashboard endpoints (RPT-005/006)
 * — never a raw AccessEvent scan.
 */
class ReportBuilderService
{
    /**
     * @return array{
     *     from: string, to: string,
     *     totals: array{detected:int, recognized:int, review:int, unknown:int, allow:int, deny:int, unique_persons_recognized:int},
     *     by_decision: list<array{decision:string, count:int}>,
     *     by_camera: list<array{camera_uuid:?string, camera_name:string, count:int}>,
     *     by_department: list<array{department_uuid:?string, department_name:string, count:int}>,
     * }
     */
    public function buildRangeSections(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = EventDailySummary::query()
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->whereDate('summary_date', '<=', $to->toDateString());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $rows = $query->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'detected' => (int) $rows->sum('event_count'),
                'recognized' => (int) $rows->where('event_type', 'FACE_RECOGNIZED')->sum('event_count'),
                'review' => (int) $rows->where('decision', 'REVIEW')->sum('event_count'),
                'unknown' => (int) $rows->where('decision', 'UNKNOWN')->sum('event_count'),
                'allow' => (int) $rows->where('decision', 'ALLOW')->sum('event_count'),
                'deny' => (int) $rows->where('decision', 'DENY')->sum('event_count'),
                'unique_persons_recognized' => (int) $rows->where('event_type', 'FACE_RECOGNIZED')->sum('unique_person_count'),
            ],
            'by_decision' => $rows->groupBy(fn ($row) => $row->decision->value)
                ->map(fn ($group, $decision) => ['decision' => $decision, 'count' => (int) $group->sum('event_count')])
                ->values()->all(),
            'by_camera' => $this->namedBreakdown($rows->groupBy('camera_id'), Camera::class, 'camera_uuid', 'camera_name', 'ไม่ระบุกล้อง'),
            'by_department' => $this->namedBreakdown($rows->groupBy('department_id'), Department::class, 'department_uuid', 'department_name', 'ไม่มีแผนกวิชา'),
        ];
    }

    /**
     * @return array<int, array{hour:string, count:int}>
     */
    public function buildHourlyBreakdown(Carbon $date, ?int $siteId = null): array
    {
        $query = EventHourlySummary::query()->whereDate('hour_bucket', $date->toDateString());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $totals = $query->get()->groupBy(fn ($row) => $row->hour_bucket->format('H:00'))
            ->map(fn ($rows) => (int) $rows->sum('event_count'));

        return collect(range(0, 23))->map(function (int $hour) use ($totals) {
            $label = sprintf('%02d:00', $hour);

            return ['hour' => $label, 'count' => (int) ($totals[$label] ?? 0)];
        })->all();
    }

    /**
     * @param  Collection<int|string, Collection<int, EventDailySummary>>  $groups
     * @param  class-string<Camera|Department>  $modelClass
     * @return list<array<string, mixed>>
     */
    private function namedBreakdown($groups, string $modelClass, string $uuidKey, string $nameKey, string $unassignedLabel): array
    {
        $ids = $groups->keys()->filter()->map(fn ($id) => (int) $id);
        $models = $modelClass::whereIn('id', $ids)->get()->keyBy('id');

        return $groups->map(function ($group, $id) use ($models, $uuidKey, $nameKey, $unassignedLabel) {
            $model = $id !== '' ? $models->get((int) $id) : null;

            return [
                $uuidKey => $model?->uuid,
                $nameKey => $model?->name ?? $unassignedLabel,
                'count' => (int) $group->sum('event_count'),
            ];
        })->values()->all();
    }
}
