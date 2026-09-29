<?php

namespace App\Http\Controllers\History;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\Person;
use App\Models\StudentStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * RPT-002 — person timeline (docs/02-api-contract.md §15
 * GET /persons/{uuid}/timeline).
 *
 * Deliberately richer than EVT-019's per-person event list
 * (GET /events?person_uuid=X): this merges TWO distinct historical
 * sources into one chronological feed — recognition events (AccessEvent,
 * "where/when was this person seen and what was decided") and academic
 * status transitions (StudentStatusHistory, "when did their standing
 * change") — since a real investigation ("why did this person start
 * getting REVIEW decisions in March?") needs both read together, not two
 * separate screens. Each source stays its own immutable, append-only
 * table; this endpoint only reads and interleaves, never writes either.
 */
class PersonTimelineController extends Controller
{
    public function show(Request $request, Person $person): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        // Parse to Carbon, not the raw string — see EventSearchQuery's own
        // comment: SQLite stores timestamps without a 'T'/'Z' separator, so
        // comparing raw ISO-8601 strings as TEXT breaks same-day `>=`.
        $from = ! empty($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = ! empty($validated['to']) ? Carbon::parse($validated['to']) : null;

        $events = AccessEvent::query()
            ->with(['site', 'zone', 'camera', 'aiNode', 'latestDecision'])
            ->where('person_id', $person->id)
            ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->get()
            ->map(fn (AccessEvent $event) => [
                'type' => 'EVENT',
                'timestamp' => $event->occurred_at,
                'event' => new AccessEventResource($event),
            ]);

        $statusChanges = collect();
        $studentProfileId = $person->studentProfile?->id;
        if ($studentProfileId !== null) {
            $statusChanges = StudentStatusHistory::query()
                ->with('changedBy')
                ->where('student_profile_id', $studentProfileId)
                ->when($from !== null, fn ($q) => $q->where('effective_from', '>=', $from))
                ->when($to !== null, fn ($q) => $q->where('effective_from', '<=', $to))
                ->get()
                ->map(fn (StudentStatusHistory $change) => [
                    'type' => 'STATUS_CHANGE',
                    'timestamp' => $change->effective_from,
                    'status_change' => [
                        'uuid' => $change->uuid,
                        'old_status' => $change->old_status?->value,
                        'new_status' => $change->new_status->value,
                        'reason' => $change->reason,
                        'source' => $change->source->value,
                        'changed_by' => $change->changedBy === null ? null : [
                            'uuid' => $change->changedBy->uuid,
                            'username' => $change->changedBy->username,
                        ],
                    ],
                ]);
        }

        $merged = $events->concat($statusChanges)->sortByDesc('timestamp')->values();
        $total = $merged->count();
        $items = $merged->forPage($page, $perPage)->map(function (array $item) {
            $item['timestamp'] = $item['timestamp']?->toISOString();

            return $item;
        })->values();

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }
}
