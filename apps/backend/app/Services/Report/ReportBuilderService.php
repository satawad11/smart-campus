<?php

namespace App\Services\Report;

use App\Models\AiNode;
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
     * @return list<array{date:string, day_name:string, count:int}>
     */
    public function buildDailyBreakdown(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = EventDailySummary::query()
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->whereDate('summary_date', '<=', $to->toDateString());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $totals = $query->get()->groupBy(fn ($row) => $row->summary_date->toDateString())
            ->map(fn ($rows) => (int) $rows->sum('event_count'));

        $breakdown = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $breakdown[] = [
                'date' => $dateStr,
                'day_name' => $cursor->format('l'),
                'count' => (int) ($totals[$dateStr] ?? 0),
            ];
            $cursor->addDay();
        }

        return $breakdown;
    }

    /**
     * @return list<array{month:string, month_name:string, count:int}>
     */
    public function buildMonthlyBreakdown(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = EventDailySummary::query()
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->whereDate('summary_date', '<=', $to->toDateString());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $totals = $query->get()->groupBy(fn ($row) => $row->summary_date->format('Y-m'))
            ->map(fn ($rows) => (int) $rows->sum('event_count'));

        $breakdown = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            $monthKey = $cursor->format('Y-m');
            $breakdown[] = [
                'month' => $monthKey,
                'month_name' => $cursor->format('F Y'),
                'count' => (int) ($totals[$monthKey] ?? 0),
            ];
            $cursor->addMonth();
        }

        return $breakdown;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReviewReport(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = EventDailySummary::query()
            ->where('decision', 'REVIEW')
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->whereDate('summary_date', '<=', $to->toDateString());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $rows = $query->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_reviews' => (int) $rows->sum('event_count'),
            'by_day' => $this->buildDailyBreakdown($from, $to, $siteId),
            'by_camera' => $this->namedBreakdown($rows->groupBy('camera_id'), Camera::class, 'camera_uuid', 'camera_name', 'ไม่ระบุกล้อง'),
            'by_department' => $this->namedBreakdown($rows->groupBy('department_id'), Department::class, 'department_uuid', 'department_name', 'ไม่มีแผนกวิชา'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUnknownReport(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = EventDailySummary::query()
            ->where('decision', 'UNKNOWN')
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->whereDate('summary_date', '<=', $to->toDateString());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $rows = $query->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_unknowns' => (int) $rows->sum('event_count'),
            'note' => 'Unknown events represent unrecognized faces and do not necessarily correspond to unique individuals.',
            'by_day' => $this->buildDailyBreakdown($from, $to, $siteId),
            'by_camera' => $this->namedBreakdown($rows->groupBy('camera_id'), Camera::class, 'camera_uuid', 'camera_name', 'ไม่ระบุกล้อง'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCameraReport(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $cameraQuery = Camera::query()->with(['site', 'zone']);
        if ($siteId !== null) {
            $cameraQuery->where('site_id', $siteId);
        }
        $cameras = $cameraQuery->get();

        $summaryQuery = EventDailySummary::query()
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->whereDate('summary_date', '<=', $to->toDateString());
        if ($siteId !== null) {
            $summaryQuery->where('site_id', $siteId);
        }
        $summariesByCamera = $summaryQuery->get()->groupBy('camera_id');

        $cameraReports = $cameras->map(function (Camera $cam) use ($summariesByCamera) {
            $rows = $summariesByCamera->get($cam->id, collect());

            return [
                'camera_uuid' => $cam->uuid,
                'camera_code' => $cam->camera_code,
                'name' => $cam->name,
                'site_name' => $cam->site?->name ?? 'ไม่ระบุไซต์',
                'zone_name' => $cam->zone?->name ?? 'ไม่ระบุโซน',
                'status' => $cam->status?->value ?? 'OFFLINE',
                'totals' => [
                    'detected' => (int) $rows->sum('event_count'),
                    'recognized' => (int) $rows->where('event_type', 'FACE_RECOGNIZED')->sum('event_count'),
                    'review' => (int) $rows->where('decision', 'REVIEW')->sum('event_count'),
                    'unknown' => (int) $rows->where('decision', 'UNKNOWN')->sum('event_count'),
                    'allow' => (int) $rows->where('decision', 'ALLOW')->sum('event_count'),
                    'deny' => (int) $rows->where('decision', 'DENY')->sum('event_count'),
                    'unique_persons_recognized' => (int) $rows->where('event_type', 'FACE_RECOGNIZED')->sum('unique_person_count'),
                ],
            ];
        })->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'cameras' => $cameraReports,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAiNodeReport(?int $siteId = null): array
    {
        $nodeQuery = AiNode::query()->with('site');
        if ($siteId !== null) {
            $nodeQuery->where('site_id', $siteId);
        }
        $nodes = $nodeQuery->get();

        $nodeReports = $nodes->map(function (AiNode $node) {
            return [
                'node_uuid' => $node->uuid,
                'node_code' => $node->node_code,
                'name' => $node->name,
                'site_name' => $node->site?->name ?? 'ไม่ระบุไซต์',
                'hostname' => $node->hostname,
                'ip_address' => $node->ip_address,
                'status' => $node->status?->value ?? 'OFFLINE',
                'is_active' => (bool) $node->is_active,
                'last_heartbeat_at' => $node->last_heartbeat_at?->toIso8601String(),
                'hardware_type' => $node->hardware_type,
                'software_version' => $node->software_version,
                'cpu_model' => $node->cpu_model,
                'gpu_name' => $node->gpu_name,
                'camera_count' => $node->cameras()->count(),
            ];
        })->values()->all();

        return [
            'nodes' => $nodeReports,
        ];
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
