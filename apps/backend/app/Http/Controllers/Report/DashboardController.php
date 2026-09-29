<?php

namespace App\Http\Controllers\Report;

use App\Enums\HealthStatus;
use App\Http\Controllers\Controller;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\EventDailySummary;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RPT-005 — dashboard summary (docs/10-module-06-reporting.md "Dashboard",
 * docs/02-api-contract.md §14 GET /dashboard/summary).
 *
 * Event metrics are read from EventDailySummary (RPT-003/004) — a request
 * to this endpoint never scans raw AccessEvent rows, that's exactly the
 * performance problem the summary layer exists to avoid. Camera/AI-node
 * online-vs-total counts are the one part of this response summaries don't
 * cover (device status is live state, not historical event volume), so
 * those come from a direct query using each device's latestHealth
 * relation.
 */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
        ]);

        $date = ! empty($validated['date']) ? $validated['date'] : now()->toDateString();

        // whereDate(), not where(): a `date` column casts through Eloquent
        // as a full 'Y-m-d 00:00:00' string, and SQLite (unlike
        // PostgreSQL's native DATE type, which discards the time part on
        // write) stores exactly that as TEXT — a plain where() comparing
        // against a bare 'Y-m-d' string then never matches. whereDate()
        // extracts just the date part on both drivers.
        $summaryQuery = EventDailySummary::query()->whereDate('summary_date', $date);
        if (! empty($validated['site_uuid'])) {
            $siteId = Site::where('uuid', $validated['site_uuid'])->value('id');
            $summaryQuery->where('site_id', $siteId);
        }
        $rows = $summaryQuery->get();

        // "detected" = every recognition attempt regardless of outcome;
        // "recognized" = attempts that matched a known person specifically
        // (docs/10's distinct detected/recognized metrics). unique_person_
        // count is NOT summed across decision/event_type buckets for the
        // headline numbers below — the same person can appear in multiple
        // buckets in one day, so summing would double-count; total unique
        // persons for the day is reported separately via a dedicated sum
        // over event_type=FACE_RECOGNIZED rows only, the closest
        // approximation available from this summary shape without a raw
        // COUNT(DISTINCT) scan.
        $cameraQuery = Camera::query()->where('status', 'ACTIVE');
        $aiNodeQuery = AiNode::query()->where('status', 'ACTIVE');
        if (! empty($validated['site_uuid'])) {
            $siteId = Site::where('uuid', $validated['site_uuid'])->value('id');
            $cameraQuery->where('site_id', $siteId);
            $aiNodeQuery->where('site_id', $siteId);
        }
        $cameras = $cameraQuery->with('latestHealth')->get();
        $aiNodes = $aiNodeQuery->with('latestHealth')->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'date' => $date,
                'events' => [
                    'detected' => (int) $rows->sum('event_count'),
                    'recognized' => (int) $rows->where('event_type', 'FACE_RECOGNIZED')->sum('event_count'),
                    'review' => (int) $rows->where('decision', 'REVIEW')->sum('event_count'),
                    'unknown' => (int) $rows->where('decision', 'UNKNOWN')->sum('event_count'),
                    'allow' => (int) $rows->where('decision', 'ALLOW')->sum('event_count'),
                    'deny' => (int) $rows->where('decision', 'DENY')->sum('event_count'),
                ],
                'unique_persons_recognized' => (int) $rows->where('event_type', 'FACE_RECOGNIZED')->sum('unique_person_count'),
                'cameras' => [
                    'online' => $cameras->filter(fn (Camera $c) => $c->latestHealth?->status === HealthStatus::ONLINE)->count(),
                    'total' => $cameras->count(),
                ],
                'ai_nodes' => [
                    'online' => $aiNodes->filter(fn (AiNode $n) => $n->latestHealth?->status === HealthStatus::ONLINE)->count(),
                    'total' => $aiNodes->count(),
                ],
            ],
            'meta' => null,
        ]);
    }
}
