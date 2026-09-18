<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audit Log API Controller (AUTH-014).
 *
 * Provides paginated inspection of administrative and security events.
 * Guarded by permission: audit.view.
 */
class AuditLogController extends Controller
{
    protected const SORTABLE = ['created_at', 'action', 'module'];

    /**
     * List paginated audit log events with server-side filters.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action' => ['sometimes', 'string', 'max:100'],
            'module' => ['sometimes', 'string', 'max:50'],
            'actor_uuid' => ['sometimes', 'string', 'uuid'],
            'user_uuid' => ['sometimes', 'string', 'uuid'],
            'subject_type' => ['sometimes', 'string', 'max:50'],
            'entity_type' => ['sometimes', 'string', 'max:50'],
            'subject_uuid' => ['sometimes', 'string', 'max:255'],
            'entity_id' => ['sometimes', 'string', 'max:255'],
            'date_from' => ['sometimes', 'string', 'max:50'],
            'date_to' => ['sometimes', 'string', 'max:50'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $query = AuditLog::query()->with('actor');

        $this->applyFilters($query, $validated);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => AuditLogResource::collection($paginator->items()),
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

    /**
     * Show a single audit log event by UUID.
     */
    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new AuditLogResource($auditLog->load('actor')),
            'meta' => null,
        ]);
    }

    /**
     * Apply request filters to the Eloquent query.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        $actorUuid = $filters['actor_uuid'] ?? $filters['user_uuid'] ?? null;
        if (! empty($actorUuid) && Str::isUuid($actorUuid)) {
            $query->whereHas('actor', function (Builder $q) use ($actorUuid) {
                $q->where('uuid', $actorUuid);
            });
        }

        $subjectType = $filters['subject_type'] ?? $filters['entity_type'] ?? null;
        if (! empty($subjectType)) {
            $query->where('entity_type', $subjectType);
        }

        $subjectUuid = $filters['subject_uuid'] ?? $filters['entity_id'] ?? null;
        if (! empty($subjectUuid)) {
            $query->where('entity_id', $subjectUuid);
        }

        if (! empty($filters['date_from'])) {
            try {
                $from = Carbon::parse($filters['date_from']);
                $query->where('created_at', '>=', $from);
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        if (! empty($filters['date_to'])) {
            try {
                $to = Carbon::parse($filters['date_to']);
                $query->where('created_at', '<=', $to);
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }
    }
}
