<?php

namespace App\Http\Controllers\History;

use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Services\Event\EventSearchQuery;
use App\Services\Vehicle\PlateSearchAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historical search (RPT-001, docs/10-module-06-reporting.md "Historical
 * Search"). Reuses EventSearchQuery — the same AccessEvent filter
 * semantics GET /events (EVT-008) already ships — plus one filter that is
 * genuinely new for this module: free-text `keyword`, matched against a
 * person's name/code and a student's student_code. A separate endpoint
 * from GET /events (per docs/02-api-contract.md §15) because this is
 * Module 06's reporting/investigation surface (report.view), not Module
 * 05's event-management surface (event.view) — same underlying rows,
 * different permission boundary and audience.
 */
class HistorySearchController extends Controller
{
    use EnforcesScope;

    private const SORTABLE = ['occurred_at', 'received_at', 'created_at'];

    public function __construct(
        private readonly EventSearchQuery $searchQuery,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'person_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:persons,uuid'],
            'student_code' => ['sometimes', 'string'],
            'event_type' => ['sometimes', 'string'],
            'decision' => ['sometimes', 'string', 'in:ALLOW,REVIEW,DENY,UNKNOWN'],
            'site_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:sites,uuid'],
            'zone_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:zones,uuid'],
            'camera_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:cameras,uuid'],
            'academic_status' => ['sometimes', 'string'],
            'plate' => ['sometimes', 'string', 'max:32'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $query = $this->scopeQuery($this->searchQuery->build($validated), $request, 'report.view');
        // VEH-013: plate searches are audited (PDPA).
        if (! empty($validated['plate'])) {
            app(PlateSearchAuditor::class)->record($request, 'history', $validated['plate']);
        }

        if (! empty($validated['keyword'])) {
            $keyword = $validated['keyword'];

            $matchingPersonIds = Person::query()
                ->where(function ($q) use ($keyword) {
                    $q->where('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%")
                        ->orWhere('display_name', 'like', "%{$keyword}%")
                        ->orWhere('person_code', 'like', "%{$keyword}%");
                })
                ->pluck('id');

            $matchingStudentPersonIds = StudentProfile::where('student_code', 'like', "%{$keyword}%")
                ->pluck('person_id');

            // No match at all -> 0 matches everything, a real but empty
            // result, never silently "no filter applied".
            $query->whereIn('person_id', $matchingPersonIds->merge($matchingStudentPersonIds)->unique()->values());
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'occurred_at';
        $order = $validated['order'] ?? 'desc';

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
}
