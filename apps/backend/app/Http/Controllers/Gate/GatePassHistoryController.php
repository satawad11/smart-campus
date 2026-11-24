<?php

namespace App\Http\Controllers\Gate;

use App\Enums\GateMatchResult;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\GatePassResource;
use App\Services\Gate\GatePassSearchQuery;
use App\Services\Vehicle\PlateSearchAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gate pass history search (GATE-008, ACR-VEH-01 Phase 5).
 *
 * Behind feature:vehicle and report.view, like historical search (RPT-001): it shows where people
 * and their cars passed, so it sits on the reporting boundary and the caller's site/zone scope is
 * applied through the pass's gate. Plate searches are audited (VEH-013). Read only.
 */
class GatePassHistoryController extends Controller
{
    use EnforcesScope;

    public function __construct(
        private readonly GatePassSearchQuery $searchQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'gate_uuid' => ['sometimes', 'string', 'uuid'],
            'site_uuid' => ['sometimes', 'string', 'uuid'],
            'person_uuid' => ['sometimes', 'string', 'uuid'],
            'vehicle_uuid' => ['sometimes', 'string', 'uuid'],
            'match_result' => ['sometimes', Rule::enum(GateMatchResult::class)],
            'decision' => ['sometimes', 'string', 'in:ALLOW,REVIEW,DENY,UNKNOWN'],
            'plate' => ['sometimes', 'string', 'max:32'],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $query = $this->searchQuery->build($validated)
            ->whereHas('gate', fn ($gates) => $this->scopeQuery($gates, $request, 'report.view'));

        if (! empty($validated['plate'])) {
            app(PlateSearchAuditor::class)->record($request, 'gate_passes', $validated['plate']);
        }

        $paginator = $query
            ->orderBy('occurred_at', $validated['order'] ?? 'desc')
            ->orderBy('id', $validated['order'] ?? 'desc')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => GatePassResource::collection($paginator->items()),
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
