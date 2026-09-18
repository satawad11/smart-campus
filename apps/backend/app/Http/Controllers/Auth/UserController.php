<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Auth\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Administrative User Management API (AUTH-010).
 *
 * Thin controller: lifecycle logic lives in UserManagementService. Public
 * identity is the UUID (implicit route-model binding by uuid); numeric ids are
 * never exposed or accepted. Authorization is via permission middleware.
 */
class UserController extends Controller
{
    private const SORTABLE = ['username', 'email', 'status', 'last_login_at', 'created_at'];

    public function __construct(
        private readonly UserManagementService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(UserStatus::cases(), 'value'))],
            'role' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = User::query()->with('roles');

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(username) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$keyword]);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['role'])) {
            $role = $validated['role'];
            $query->whereHas('roles', function ($q) use ($role) {
                // Match by role code; only compare the uuid column when the
                // value is actually a UUID (PostgreSQL rejects non-uuid casts).
                $q->where('roles.code', $role);
                if (Str::isUuid($role)) {
                    $q->orWhere('roles.uuid', $role);
                }
            });
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => UserResource::collection($paginator->items()),
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

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->create(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'User created',
            'data' => new UserResource($user->load('roles')),
            'meta' => null,
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new UserResource($user->load('roles')),
            'meta' => null,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->service->update(
            $user,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'User updated',
            'data' => new UserResource($updated->load('roles')),
            'meta' => null,
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $updated = $this->service->changeStatus(
            $user,
            UserStatus::from($request->validated('status')),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'User status updated',
            'data' => new UserResource($updated->load('roles')),
            'meta' => null,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->service->resetPassword(
            $user,
            $request->validated('password'),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Password reset. The user must change it at next login.',
            'data' => null,
            'meta' => null,
        ]);
    }
}
