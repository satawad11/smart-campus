<?php

namespace App\Http\Controllers\Event;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Person;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event history API (EVT-008). Read-only: browsing already-persisted
 * AccessEvent + AccessDecision rows. Never accepts or computes a decision
 * itself — reviewing one is EVT-009/EVT-010, not built yet.
 *
 * Filters match docs/02-api-contract.md §12 exactly: from, to, person_uuid,
 * student_code, event_type, decision, site_uuid, zone_uuid, camera_uuid,
 * academic_status.
 */
class EventController extends Controller
{
    private const SORTABLE = ['occurred_at', 'received_at', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'person_uuid' => ['sometimes', 'string', 'exists:persons,uuid'],
            'student_code' => ['sometimes', 'string'],
            'event_type' => ['sometimes', 'string'],
            'decision' => ['sometimes', 'string', 'in:ALLOW,REVIEW,DENY,UNKNOWN'],
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
            'zone_uuid' => ['sometimes', 'string', 'exists:zones,uuid'],
            'camera_uuid' => ['sometimes', 'string', 'exists:cameras,uuid'],
            'academic_status' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'occurred_at';
        $order = $validated['order'] ?? 'desc';

        $query = AccessEvent::query()->with(['person', 'site', 'zone', 'camera', 'aiNode', 'latestDecision']);

        if (! empty($validated['from'])) {
            $query->where('occurred_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('occurred_at', '<=', $validated['to']);
        }
        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        foreach ([
            'person_uuid' => [Person::class, 'person_id'],
            'site_uuid' => [Site::class, 'site_id'],
            'zone_uuid' => [Zone::class, 'zone_id'],
            'camera_uuid' => [Camera::class, 'camera_id'],
        ] as $param => [$model, $column]) {
            if (! empty($validated[$param])) {
                $id = $model::where('uuid', $validated[$param])->value('id');
                $query->where($column, $id);
            }
        }

        if (! empty($validated['student_code'])) {
            $personId = StudentProfile::where('student_code', $validated['student_code'])->value('person_id');
            $query->where('person_id', $personId ?? 0); // 0 matches nothing — a real but unmatched filter, not "all"
        }

        if (! empty($validated['academic_status'])) {
            $personIds = StudentProfile::where('academic_status', $validated['academic_status'])->pluck('person_id');
            $query->whereIn('person_id', $personIds);
        }

        if (! empty($validated['decision'])) {
            $latestDecisionIds = AccessDecision::selectRaw('MAX(id)')->groupBy('event_id');
            $query->whereHas('decisions', function ($q) use ($validated, $latestDecisionIds) {
                $q->where('decision', $validated['decision'])->whereIn('id', $latestDecisionIds);
            });
        }

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

    public function show(AccessEvent $accessEvent): JsonResponse
    {
        $accessEvent->load(['person', 'site', 'zone', 'camera', 'aiNode', 'latestDecision', 'decisions.decidedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new AccessEventResource($accessEvent),
            'meta' => null,
        ]);
    }
}
