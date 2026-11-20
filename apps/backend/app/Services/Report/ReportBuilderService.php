<?php

namespace App\Services\Report;

use App\Enums\GateMatchResult;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Department;
use App\Models\EventDailySummary;
use App\Models\EventHourlySummary;
use App\Models\Gate;
use App\Models\GatePass;
use Illuminate\Support\Carbon;

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
            ->where('summary_date', '>=', $from->toDateString())
            ->where('summary_date', '<=', $to->toDateString().' 23:59:59');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $rows = $query->select([
            'camera_id',
            'department_id',
            'event_type',
            'decision',
            'event_count',
            'unique_person_count',
        ])->toBase()->get();

        $detected = 0;
        $recognized = 0;
        $review = 0;
        $unknown = 0;
        $allow = 0;
        $deny = 0;
        $uniquePersons = 0;
        $byDecisionCounts = [];
        $byCameraCounts = [];
        $byDepartmentCounts = [];

        foreach ($rows as $row) {
            $count = (int) $row->event_count;
            $detected += $count;
            $dec = (string) $row->decision;
            $byDecisionCounts[$dec] = ($byDecisionCounts[$dec] ?? 0) + $count;

            if ($row->event_type === 'FACE_RECOGNIZED') {
                $recognized += $count;
                $uniquePersons += (int) $row->unique_person_count;
            }
            if ($dec === 'REVIEW') {
                $review += $count;
            } elseif ($dec === 'UNKNOWN') {
                $unknown += $count;
            } elseif ($dec === 'ALLOW') {
                $allow += $count;
            } elseif ($dec === 'DENY') {
                $deny += $count;
            }

            $camId = $row->camera_id !== null ? (int) $row->camera_id : '';
            $byCameraCounts[$camId] = ($byCameraCounts[$camId] ?? 0) + $count;

            $deptId = $row->department_id !== null ? (int) $row->department_id : '';
            $byDepartmentCounts[$deptId] = ($byDepartmentCounts[$deptId] ?? 0) + $count;
        }

        $byDecisionList = [];
        foreach ($byDecisionCounts as $decision => $count) {
            $byDecisionList[] = ['decision' => $decision, 'count' => $count];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'detected' => $detected,
                'recognized' => $recognized,
                'review' => $review,
                'unknown' => $unknown,
                'allow' => $allow,
                'deny' => $deny,
                'unique_persons_recognized' => $uniquePersons,
            ],
            'by_decision' => $byDecisionList,
            'by_camera' => $this->formatDimensionBreakdown($byCameraCounts, Camera::class, 'camera_uuid', 'camera_name', 'ไม่ระบุกล้อง'),
            'by_department' => $this->formatDimensionBreakdown($byDepartmentCounts, Department::class, 'department_uuid', 'department_name', 'ไม่มีแผนกวิชา'),
            'plates' => $this->buildPlateSection($from, $to, $siteId),
            'gates' => $this->buildGateSection($from, $to, $siteId),
        ];
    }

    /**
     * VEH-012: plate reads in the range, counted straight from access_events. Plate events have
     * no decision, so the decision-based daily summaries above never include them and the face
     * totals stay unchanged.
     *
     * @return array{recognized:int, unknown:int, low_confidence:int, blocked_vehicle_reads:int}
     */
    public function buildPlateSection(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = AccessEvent::query()
            ->whereIn('event_type', ['PLATE_RECOGNIZED', 'PLATE_UNKNOWN', 'PLATE_LOW_CONFIDENCE'])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay());
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $byType = (clone $query)->selectRaw('event_type, COUNT(*) as c')->groupBy('event_type')->pluck('c', 'event_type');
        $blocked = (clone $query)->where('event_type', 'PLATE_RECOGNIZED')
            ->where('metadata->plate->vehicle_status', 'BLOCKED')->count();

        return [
            'recognized' => (int) ($byType['PLATE_RECOGNIZED'] ?? 0),
            'unknown' => (int) ($byType['PLATE_UNKNOWN'] ?? 0),
            'low_confidence' => (int) ($byType['PLATE_LOW_CONFIDENCE'] ?? 0),
            'blocked_vehicle_reads' => $blocked,
        ];
    }

    /**
     * GATE-008: Smart Gate passes in the range, one row per gate that had a pass. Counted straight
     * from gate_passes (by match_result) and each pass's current decision (its latest decision,
     * so an officer's review counts). A pass whose decision is still pending counts as undecided.
     * Passes are a view over events already in the totals above, so nothing here adds to them.
     *
     * @return list<array{gate_uuid:string, gate_code:string, gate_name:string, total:int, by_match_result:array<string,int>, by_decision:array<string,int>}>
     */
    public function buildGateSection(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $passes = GatePass::query()
            ->where('gate_passes.occurred_at', '>=', $from->copy()->startOfDay())
            ->where('gate_passes.occurred_at', '<=', $to->copy()->endOfDay());
        if ($siteId !== null) {
            $passes->whereIn('gate_passes.gate_id', Gate::withTrashed()->where('site_id', $siteId)->select('id'));
        }

        $byMatch = (clone $passes)
            ->selectRaw('gate_passes.gate_id, gate_passes.match_result, COUNT(*) as c')
            ->groupBy('gate_passes.gate_id', 'gate_passes.match_result')
            ->toBase()->get();
        if ($byMatch->isEmpty()) {
            return [];
        }

        $latestIds = AccessDecision::query()->whereNotNull('gate_pass_id')->selectRaw('MAX(id)')->groupBy('gate_pass_id');
        $byDecision = (clone $passes)
            ->join('access_decisions', 'access_decisions.gate_pass_id', '=', 'gate_passes.id')
            ->whereIn('access_decisions.id', $latestIds)
            ->selectRaw('gate_passes.gate_id, access_decisions.decision, COUNT(*) as c')
            ->groupBy('gate_passes.gate_id', 'access_decisions.decision')
            ->toBase()->get();

        $rows = [];
        foreach ($byMatch as $row) {
            $gateId = (int) $row->gate_id;
            $rows[$gateId] ??= [
                'total' => 0,
                'by_match_result' => array_fill_keys(array_column(GateMatchResult::cases(), 'value'), 0),
                'by_decision' => ['ALLOW' => 0, 'REVIEW' => 0, 'DENY' => 0, 'UNKNOWN' => 0, 'UNDECIDED' => 0],
            ];
            $rows[$gateId]['total'] += (int) $row->c;
            $rows[$gateId]['by_match_result'][(string) $row->match_result] = (int) $row->c;
        }
        foreach ($byDecision as $row) {
            $gateId = (int) $row->gate_id;
            if (isset($rows[$gateId])) {
                $rows[$gateId]['by_decision'][(string) $row->decision] = (int) $row->c;
            }
        }

        $gates = Gate::withTrashed()->whereIn('id', array_keys($rows))->get(['id', 'uuid', 'code', 'name'])->keyBy('id');
        $list = [];
        foreach ($rows as $gateId => $row) {
            $decided = array_sum($row['by_decision']);
            $row['by_decision']['UNDECIDED'] = max(0, $row['total'] - $decided);
            $gate = $gates->get($gateId);
            $list[] = [
                'gate_uuid' => (string) $gate?->uuid,
                'gate_code' => (string) $gate?->code,
                'gate_name' => (string) $gate?->name,
                ...$row,
            ];
        }
        usort($list, fn (array $a, array $b) => strcmp($a['gate_name'], $b['gate_name']));

        return $list;
    }

    /**
     * @return array<int, array{hour:string, count:int}>
     */
    public function buildHourlyBreakdown(Carbon $date, ?int $siteId = null): array
    {
        $dateStr = $date->toDateString();
        $query = EventHourlySummary::query()
            ->where('hour_bucket', '>=', "{$dateStr} 00:00:00")
            ->where('hour_bucket', '<=', "{$dateStr} 23:59:59");
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $rows = $query->select(['hour_bucket', 'event_count'])->toBase()->get();
        $totals = [];
        foreach ($rows as $row) {
            $hour = substr((string) $row->hour_bucket, 11, 2).':00';
            $totals[$hour] = ($totals[$hour] ?? 0) + (int) $row->event_count;
        }

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
            ->where('summary_date', '>=', $from->toDateString())
            ->where('summary_date', '<=', $to->toDateString().' 23:59:59');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $rows = $query->select(['summary_date', 'event_count'])->toBase()->get();
        $totals = [];
        foreach ($rows as $row) {
            $dateStr = substr((string) $row->summary_date, 0, 10);
            $totals[$dateStr] = ($totals[$dateStr] ?? 0) + (int) $row->event_count;
        }

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
            ->where('summary_date', '>=', $from->toDateString())
            ->where('summary_date', '<=', $to->toDateString().' 23:59:59');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $rows = $query->select(['summary_date', 'event_count'])->toBase()->get();
        $totals = [];
        foreach ($rows as $row) {
            $monthKey = substr((string) $row->summary_date, 0, 7);
            $totals[$monthKey] = ($totals[$monthKey] ?? 0) + (int) $row->event_count;
        }

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
            ->where('summary_date', '>=', $from->toDateString())
            ->where('summary_date', '<=', $to->toDateString().' 23:59:59');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $rows = $query->select(['camera_id', 'department_id', 'event_count'])->toBase()->get();

        $totalReviews = 0;
        $byCameraCounts = [];
        $byDepartmentCounts = [];
        foreach ($rows as $row) {
            $count = (int) $row->event_count;
            $totalReviews += $count;

            $camId = $row->camera_id !== null ? (int) $row->camera_id : '';
            $byCameraCounts[$camId] = ($byCameraCounts[$camId] ?? 0) + $count;

            $deptId = $row->department_id !== null ? (int) $row->department_id : '';
            $byDepartmentCounts[$deptId] = ($byDepartmentCounts[$deptId] ?? 0) + $count;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_reviews' => $totalReviews,
            'by_day' => $this->buildDailyBreakdown($from, $to, $siteId),
            'by_camera' => $this->formatDimensionBreakdown($byCameraCounts, Camera::class, 'camera_uuid', 'camera_name', 'ไม่ระบุกล้อง'),
            'by_department' => $this->formatDimensionBreakdown($byDepartmentCounts, Department::class, 'department_uuid', 'department_name', 'ไม่มีแผนกวิชา'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUnknownReport(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = EventDailySummary::query()
            ->where('decision', 'UNKNOWN')
            ->where('summary_date', '>=', $from->toDateString())
            ->where('summary_date', '<=', $to->toDateString().' 23:59:59');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $rows = $query->select(['camera_id', 'event_count'])->toBase()->get();

        $totalUnknowns = 0;
        $byCameraCounts = [];
        foreach ($rows as $row) {
            $count = (int) $row->event_count;
            $totalUnknowns += $count;

            $camId = $row->camera_id !== null ? (int) $row->camera_id : '';
            $byCameraCounts[$camId] = ($byCameraCounts[$camId] ?? 0) + $count;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_unknowns' => $totalUnknowns,
            'note' => 'Unknown events represent unrecognized faces and do not necessarily correspond to unique individuals.',
            'by_day' => $this->buildDailyBreakdown($from, $to, $siteId),
            'by_camera' => $this->formatDimensionBreakdown($byCameraCounts, Camera::class, 'camera_uuid', 'camera_name', 'ไม่ระบุกล้อง'),
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
            ->where('summary_date', '>=', $from->toDateString())
            ->where('summary_date', '<=', $to->toDateString().' 23:59:59');
        if ($siteId !== null) {
            $summaryQuery->where('site_id', $siteId);
        }
        $summaryRows = $summaryQuery->select([
            'camera_id',
            'event_type',
            'decision',
            'event_count',
            'unique_person_count',
        ])->toBase()->get();

        $byCamera = [];
        foreach ($summaryRows as $row) {
            $camId = (int) $row->camera_id;
            if (! isset($byCamera[$camId])) {
                $byCamera[$camId] = [
                    'detected' => 0,
                    'recognized' => 0,
                    'review' => 0,
                    'unknown' => 0,
                    'allow' => 0,
                    'deny' => 0,
                    'unique_persons_recognized' => 0,
                ];
            }
            $count = (int) $row->event_count;
            $byCamera[$camId]['detected'] += $count;
            if ($row->event_type === 'FACE_RECOGNIZED') {
                $byCamera[$camId]['recognized'] += $count;
                $byCamera[$camId]['unique_persons_recognized'] += (int) $row->unique_person_count;
            }
            $dec = (string) $row->decision;
            if ($dec === 'REVIEW') {
                $byCamera[$camId]['review'] += $count;
            } elseif ($dec === 'UNKNOWN') {
                $byCamera[$camId]['unknown'] += $count;
            } elseif ($dec === 'ALLOW') {
                $byCamera[$camId]['allow'] += $count;
            } elseif ($dec === 'DENY') {
                $byCamera[$camId]['deny'] += $count;
            }
        }

        $cameraReports = $cameras->map(function (Camera $cam) use ($byCamera) {
            $totals = $byCamera[$cam->id] ?? [
                'detected' => 0,
                'recognized' => 0,
                'review' => 0,
                'unknown' => 0,
                'allow' => 0,
                'deny' => 0,
                'unique_persons_recognized' => 0,
            ];

            return [
                'camera_uuid' => $cam->uuid,
                'camera_code' => $cam->camera_code,
                'name' => $cam->name,
                'site_name' => $cam->site?->name ?? 'ไม่ระบุไซต์',
                'zone_name' => $cam->zone?->name ?? 'ไม่ระบุโซน',
                'status' => $cam->status?->value ?? 'OFFLINE',
                'totals' => $totals,
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
     * @param  array<int|string, int>  $countsById
     * @param  class-string<Camera|Department>  $modelClass
     * @return list<array<string, mixed>>
     */
    private function formatDimensionBreakdown(array $countsById, string $modelClass, string $uuidKey, string $nameKey, string $unassignedLabel): array
    {
        $ids = array_values(array_filter(array_keys($countsById), fn ($id) => $id !== null && $id !== ''));
        $models = ! empty($ids) ? $modelClass::whereIn('id', $ids)->get()->keyBy('id') : collect();

        $result = [];
        foreach ($countsById as $id => $count) {
            $model = ($id !== null && $id !== '') ? $models->get((int) $id) : null;
            $result[] = [
                $uuidKey => $model?->uuid,
                $nameKey => $model?->name ?? $unassignedLabel,
                'count' => $count,
            ];
        }

        return $result;
    }
}
