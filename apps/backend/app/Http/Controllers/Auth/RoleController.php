<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RoleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRolePermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\Audit\AuditRequestContext;
use App\Services\Auth\RoleManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrative Role Management API (AUTH-011). Thin controller; lifecycle and
 * matrix logic live in RoleManagementService. Public identity is the UUID.
 */
class RoleController extends Controller
{
    private const SORTABLE = ['code', 'name', 'status', 'system_role', 'created_at'];

    public function __construct(
        private readonly RoleManagementService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(RoleStatus::cases(), 'value'))],
            'system_role' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'code';
        $order = $validated['order'] ?? 'asc';

        $query = Role::query()->withCount(['permissions', 'userAssignments']);

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('system_role', $validated)) {
            $query->where('system_role', (bool) $validated['system_role']);
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => RoleResource::collection($paginator->items()),
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

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->service->create(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Role created',
            'data' => new RoleResource($role->loadCount(['permissions', 'userAssignments'])),
            'meta' => null,
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new RoleResource($role->load('permissions')->loadCount(['permissions', 'userAssignments'])),
            'meta' => null,
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->service->update(
            $role,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Role updated',
            'data' => new RoleResource($updated->loadCount(['permissions', 'userAssignments'])),
            'meta' => null,
        ]);
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $updated = $this->service->syncPermissions(
            $role,
            $request->validated('permissions'),
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Role permissions updated',
            'data' => new RoleResource($updated->loadCount(['permissions', 'userAssignments'])),
            'meta' => null,
        ]);
    }
}
