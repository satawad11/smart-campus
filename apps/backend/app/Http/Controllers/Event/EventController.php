<?php

namespace App\Http\Controllers\Event;

use App\Enums\AccessDecisionOutcome;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Event\ReviewEventRequest;
use App\Http\Resources\AccessDecisionResource;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Services\Audit\AuditRequestContext;
use App\Services\Event\EventReviewService;
use App\Services\Event\EventSearchQuery;
use App\Services\Event\EventSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event history + review queue + manual review API (EVT-008/EVT-009/EVT-010).
 * Browsing (index/show) is read-only over already-persisted AccessEvent +
 * AccessDecision rows. review() is the one write path: it always appends a
 * new AccessDecision, never edits an existing one (docs/09: "Officer review
 * adds a new decision. It never overwrites system decision").
 *
 * The review queue (docs/00-system-blueprint.md §9, ACCEPTANCE_CRITERIA.md
 * "Review queue works") is not a separate endpoint — docs/02-api-contract.md
 * §12 defines only GET /events with a `decision` filter, no dedicated queue
 * route. `?decision=REVIEW` IS the queue: it matches each event's latest
 * decision (never the original), so an event drops out the moment an
 * officer's review supersedes it with ALLOW/DENY (docs/09 "Officer review
 * adds a new decision. It never overwrites system decision"). The only
 * queue-specific behavior added here is default ordering: oldest-first
 * (FIFO) when filtering to decision=REVIEW, since that is how a work queue
 * is worked, vs. newest-first for general history browsing.
 *
 * Filters match docs/02-api-contract.md §12 exactly: from, to, person_uuid,
 * student_code, event_type, decision, site_uuid, zone_uuid, camera_uuid,
 * academic_status.
 */
class EventController extends Controller
{
    use EnforcesScope;

    private const SORTABLE = ['occurred_at', 'received_at', 'created_at'];

    public function __construct(
        private readonly EventReviewService $reviewService,
        private readonly EventSearchQuery $searchQuery,
        private readonly EventSnapshotService $snapshots,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            // 'bail' + 'uuid' before 'exists': PostgreSQL's uuid column rejects
            // a malformed string at the SQL level (invalid input syntax), so
            // format must be checked first or an invalid value 500s instead
            // of failing validation cleanly (SQLite's text-typed uuid column
            // never surfaced this). See ReviewEventRequest.
            'person_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:persons,uuid'],
            'student_code' => ['sometimes', 'string'],
            'event_type' => ['sometimes', 'string'],
            'decision' => ['sometimes', 'string', 'in:ALLOW,REVIEW,DENY,UNKNOWN'],
            'site_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:sites,uuid'],
            'zone_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:zones,uuid'],
            'camera_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:cameras,uuid'],
            'academic_status' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'occurred_at';
        // Review queue (decision=REVIEW): default oldest-first, matching how
        // a work queue is worked. General history browsing: newest-first.
        $defaultOrder = ($validated['decision'] ?? null) === 'REVIEW' ? 'asc' : 'desc';
        $order = $validated['order'] ?? $defaultOrder;

        $query = $this->scopeQuery($this->searchQuery->build($validated), $request, 'event.view');

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => AccessEventResource::collection($paginator->items()),
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

    public function show(Request $request, AccessEvent $accessEvent): JsonResponse
    {
        $this->assertInScope($request, 'event.view', $accessEvent->site_id, $accessEvent->zone_id);
        $accessEvent->load(['person.studentProfile', 'person.staffProfile', 'site', 'zone', 'camera', 'aiNode', 'latestDecision', 'decisions.decidedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new AccessEventResource($accessEvent),
            'meta' => null,
        ]);
    }

    /**
     * EVT-025 (ACR-EVT-24): the event's face crop for an authorized operator.
     * Out-of-scope events answer 404 like the event itself; each successful
     * view is audited. Returned only here, never in ordinary event JSON, and
     * marked not cacheable.
     */
    public function snapshot(Request $request, AccessEvent $accessEvent): JsonResponse
    {
        $this->assertInScope($request, 'event.view', $accessEvent->site_id, $accessEvent->zone_id);

        $bytes = $this->snapshots->read($accessEvent, $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'event_uuid' => $accessEvent->uuid,
                'content_type' => 'image/jpeg',
                'image_base64' => base64_encode($bytes),
            ],
            'meta' => null,
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function review(ReviewEventRequest $request, AccessEvent $accessEvent): JsonResponse
    {
        $this->assertInScope($request, 'event.review', $accessEvent->site_id, $accessEvent->zone_id);
        $validated = $request->validated();

        $created = $this->reviewService->review(
            $accessEvent,
            AccessDecisionOutcome::from($validated['decision']),
            $validated['reason_code'],
            $validated['reason_text'] ?? null,
            $request->user(),
            AuditRequestContext::fromRequest($request),
            $validated['expected_decision_uuid'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Review recorded.',
            'data' => new AccessDecisionResource($created),
            'meta' => null,
        ], 201);
    }
}
