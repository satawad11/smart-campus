<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginHistoryResource;
use App\Models\LoginLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Administrative Login History API (AUTH-013).
 *
 * Provides paginated, filterable access to login attempt records.
 * Supports global query (permission: audit.view) and target user-scoped
 * query (permission: user.view).
 */
class LoginHistoryController extends Controller
{
    protected const SORTABLE = ['created_at', 'username', 'success', 'ip_address'];

    /**
     * List global login history across all users and unknown attempts.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateQueryParams($request);

        $query = LoginLog::query()->with('user');

        $this->applyFilters($query, $validated);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => LoginHistoryResource::collection($paginator->items()),
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
     * List login history for a specific target user.
     */
    public function userHistory(Request $request, User $user): JsonResponse
    {
        $validated = $this->validateQueryParams($request);

        $query = LoginLog::query()
            ->with('user')
            ->where('user_id', $user->id);

        $this->applyFilters($query, $validated);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => LoginHistoryResource::collection($paginator->items()),
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
     * Validate query parameters for login history endpoints.
     *
     * @return array<string, mixed>
     */
    protected function validateQueryParams(Request $request): array
    {
        return $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'username' => ['sometimes', 'string', 'max:255'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:50'],
            'success' => ['sometimes', 'boolean'],
            'user_uuid' => ['sometimes', 'string', 'uuid'],
            'ip_address' => ['sometimes', 'string', 'max:45'],
            'date_from' => ['sometimes', 'string', 'max:50'],
            'date_to' => ['sometimes', 'string', 'max:50'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);
    }

    /**
     * Apply request filters to the Eloquent builder query.
     *
     * @param  Builder<LoginLog>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        // Username / Keyword filter
        $searchTerm = $filters['keyword'] ?? $filters['username'] ?? null;
        if (! empty($searchTerm)) {
            $term = '%'.mb_strtolower($searchTerm).'%';
            $query->whereRaw('LOWER(username) LIKE ?', [$term]);
        }

        // Status filter
        if (! empty($filters['status'])) {
            $status = strtoupper((string) $filters['status']);
            if ($status === 'SUCCESS') {
                $query->where('success', true);
            } else {
                $query->where('failure_reason', $status);
            }
        }

        // Explicit boolean success filter
        if (isset($filters['success'])) {
            $query->where('success', (bool) $filters['success']);
        }

        // Target user UUID filter
        if (! empty($filters['user_uuid']) && Str::isUuid($filters['user_uuid'])) {
            $userUuid = $filters['user_uuid'];
            $query->whereHas('user', function (Builder $q) use ($userUuid) {
                $q->where('uuid', $userUuid);
            });
        }

        // IP Address filter
        if (! empty($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        // Date range filters (ISO 8601 parsing)
        if (! empty($filters['date_from'])) {
            try {
                $from = Carbon::parse($filters['date_from']);
                $query->where('created_at', '>=', $from);
            } catch (Throwable) {
                // Ignore unparseable date values
            }
        }

        if (! empty($filters['date_to'])) {
            try {
                $to = Carbon::parse($filters['date_to']);
                $query->where('created_at', '<=', $to);
            } catch (Throwable) {
                // Ignore unparseable date values
            }
        }
    }
}
